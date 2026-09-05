<?php

declare(strict_types=1);

namespace App\Modules\Mx\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Mx\Database\Seeders\MxStatesSeeder;
use App\Modules\Mx\Models\PostalCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa el catálogo de SEPOMEX a `mx_states` + `mx_postal_codes`.
 *
 * ```
 * php artisan mx:sepomex:import storage/app/CPdescarga.txt
 * php artisan mx:sepomex:import --url=https://…/CPdescarga.txt --dry-run
 * ```
 *
 * El archivo NO viaja en el repositorio: son catorce megas de un tercero con su
 * propia licencia (ver `docs/modules/mx.md`, que dice de dónde se descarga).
 *
 * ## Lo que el comando se traga sin quejarse
 *
 * El archivo oficial de Correos de México va separado por `|` y codificado en
 * ISO-8859-1; el mismo catálogo circula convertido a coma y UTF-8 en media
 * docena de repositorios. Las dos formas entran, porque el separador se deduce
 * de la cabecera y la codificación se detecta leyendo los primeros kilobytes: un
 * archivo que ya es UTF-8 válido se lee tal cual y el resto pasa por un filtro
 * `iconv`. Convertir a ciegas un archivo ya convertido produce «Ãlvaro Obregón»,
 * que es exactamente el fallo que nadie mira hasta que sale impreso.
 *
 * También reconstruye los ceros de la izquierda: varias copias del CSV han
 * pasado por una hoja de cálculo que convirtió `01000` en el número `1000`, y un
 * código postal de cuatro dígitos no existe.
 *
 * ## Por qué está detrás del toggle
 *
 * Con `MX_ENABLED=false` el comando no se registra. Podría parecer que
 * importar el catálogo debería poder hacerse siempre —la tabla existe—, pero sin
 * el módulo encendido nadie la consulta: llenarla serían 145 000 filas
 * inertes y un `mx:sepomex:import` en el cron que nadie recuerda haber puesto.
 *
 * ## Idempotencia
 *
 * Escribe con `upsert` sobre `(postal_code, settlement, settlement_type)`, así
 * que correrlo dos veces deja la misma tabla y una versión nueva del catálogo
 * actualiza las filas existentes en vez de duplicarlas. Lo que **no** hace es
 * borrar: un asentamiento que SEPOMEX retire sigue en la tabla. Es deliberado
 * —una dirección guardada hace tres años tiene que poder seguir mostrándose—, y
 * quien quiera una tabla limpia la vacía antes de importar.
 */
#[Description('Importa el catálogo de códigos postales de SEPOMEX desde su CSV oficial')]
#[Signature('mx:sepomex:import {path? : Ruta al CSV de SEPOMEX} {--url= : Descárgalo de esta URL en vez de leer un archivo}')]
final class SepomexImportCommand extends Command
{
    use SupportsDryRun;

    /** Cabeceras que tiene que traer el archivo, ya en minúscula. */
    private const array REQUIRED_HEADERS = ['d_codigo', 'd_asenta', 'd_tipo_asenta', 'd_mnpio', 'c_estado'];

    public function handle(): int
    {
        $downloaded = null;

        try {
            $path = $this->resolveSource($downloaded);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->import($path);
        } finally {
            if ($downloaded !== null && is_file($downloaded)) {
                unlink($downloaded);
            }
        }
    }

    /**
     * De dónde se lee: el argumento o la descarga.
     *
     * `$downloaded` sale con la ruta del temporal cuando se ha descargado, para
     * que el `finally` de `handle()` lo borre pase lo que pase.
     *
     * @throws RuntimeException
     */
    private function resolveSource(?string &$downloaded): string
    {
        $path = $this->argument('path');
        $url = $this->option('url');

        $path = is_string($path) && $path !== '' ? $path : null;
        $url = is_string($url) && $url !== '' ? $url : null;

        if ($path !== null && $url !== null) {
            throw new RuntimeException('Pasa una ruta o --url, no las dos: el comando no sabría cuál de las dos manda.');
        }

        if ($url !== null) {
            $downloaded = $this->download($url);

            return $downloaded;
        }

        if ($path === null) {
            throw new RuntimeException('Falta la ruta al CSV de SEPOMEX (o --url). Ver docs/modules/mx.md para saber de dónde se descarga.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se puede leer {$path}.");
        }

        return $path;
    }

    /**
     * Descarga el catálogo a un temporal.
     *
     * Va a disco con `sink()` y no a memoria: el archivo son catorce megas, y
     * cargarlos en un string para volver a escribirlos es pagar dos veces lo
     * mismo en un proceso que después va a leer la línea por línea.
     *
     * El nombre se compone con `Str::random()` y no con `tempnam()`: el preset
     * `security` de Pest prohíbe esa función —crea el archivo con permisos por
     * defecto y devuelve una ruta predecible— y aquí no hace falta, porque
     * `sink()` crea el archivo él mismo y 32 caracteres aleatorios no los
     * adivina nadie.
     *
     * @throws RuntimeException
     */
    private function download(string $url): string
    {
        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sepomex-'.Str::random(32).'.txt';

        $this->components->info("Descargando el catálogo de {$url}...");

        $response = Http::timeout(300)->sink($temp)->get($url);

        if ($response->failed()) {
            if (is_file($temp)) {
                unlink($temp);
            }

            throw new RuntimeException("La descarga devolvió {$response->status()}.");
        }

        return $temp;
    }

    /**
     * Lee el archivo y escribe (o cuenta, con `--dry-run`).
     */
    private function import(string $path): int
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->components->error("No se pudo abrir {$path}.");

            return self::FAILURE;
        }

        if (! $this->isUtf8($path)) {
            /*
             * El filtro convierte al vuelo, así que `fgetcsv` ve UTF-8 y no hay
             * que acordarse de convertir campo a campo más abajo. `//TRANSLIT`
             * queda fuera a propósito: ISO-8859-1 cabe entero en UTF-8 y una
             * transliteración sólo podría empeorar un nombre propio.
             */
            stream_filter_append($handle, 'convert.iconv.ISO-8859-1/UTF-8');
        }

        try {
            return $this->readAndWrite($handle, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function readAndWrite($handle, string $path): int
    {
        $dryRun = $this->isDryRun();
        $chunkSize = max(1, (int) config('mx.import.chunk_size', 1000));

        $delimiter = null;
        $columns = null;
        $batch = [];
        $rows = 0;
        $skipped = 0;
        $duplicates = 0;

        // El `upsert` de la primera tanda necesita las 32 entidades ya
        // sembradas: la FK de mx_postal_codes apunta a mx_states.code.
        if (! $dryRun) {
            (new MxStatesSeeder)->run();
            DB::beginTransaction();
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ($columns === null) {
                    if (! str_contains(mb_strtolower($line), 'd_codigo')) {
                        // El archivo oficial trae una línea de aviso antes de la
                        // cabecera; lo que no sea la cabecera se salta.
                        continue;
                    }

                    $delimiter = substr_count($line, '|') > substr_count($line, ',') ? '|' : ',';
                    $columns = $this->headerMap($line, $delimiter);

                    continue;
                }

                $row = $this->parseRow($line, (string) $delimiter, $columns);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $key = $this->keyOf($row);

                if (isset($batch[$key])) {
                    $duplicates++;
                } else {
                    $rows++;
                }

                $batch[$key] = $row;

                if (count($batch) >= $chunkSize) {
                    if (! $dryRun) {
                        $this->write($batch);
                    }

                    $batch = [];
                    $this->reportProgress($rows, $chunkSize);
                }
            }

            if ($columns === null) {
                throw new RuntimeException("No se encontró la cabecera de SEPOMEX en {$path}: falta d_codigo.");
            }

            if ($batch !== [] && ! $dryRun) {
                $this->write($batch);
            }

            if (! $dryRun) {
                DB::commit();
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }

            if ($e instanceof RuntimeException) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            throw $e;
        }

        if ($dryRun) {
            $this->dryRunNotice(sprintf(
                'se importarían %d asentamiento(s) desde %s (%d repetido(s), %d línea(s) ilegible(s)) y mx_states quedaría con las 32 entidades.',
                $rows,
                $path,
                $duplicates,
                $skipped,
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'mx:sepomex:import — %d asentamiento(s) importado(s), %d repetido(s), %d línea(s) saltada(s). La tabla tiene ahora %d fila(s).',
            $rows,
            $duplicates,
            $skipped,
            PostalCode::query()->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Un lote a la tabla.
     *
     * @param array<string, array{postal_code: string, settlement: string, settlement_type: string, municipality: string, city: string|null, state_code: string}> $batch
     */
    private function write(array $batch): void
    {
        PostalCode::query()->upsert(
            array_values($batch),
            ['postal_code', 'settlement', 'settlement_type'],
            ['municipality', 'city', 'state_code'],
        );
    }

    /**
     * Un aviso cada diez lotes: bastante para saber que sigue vivo en una
     * importación de dos minutos, poco para llenar el log de un cron.
     */
    private function reportProgress(int $rows, int $chunkSize): void
    {
        if ($rows % ($chunkSize * 10) === 0) {
            $this->components->twoColumnDetail('Procesados', (string) $rows);
        }
    }

    /**
     * Posición de cada columna que interesa, por nombre y en minúscula.
     *
     * SEPOMEX escribe `D_mnpio` con la primera en mayúscula y el resto en
     * minúscula, y las copias que circulan por ahí no siempre lo respetan.
     *
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    private function headerMap(string $line, string $delimiter): array
    {
        $headers = str_getcsv(trim($line), $delimiter, '"', '');

        /** @var array<string, int> $map */
        $map = [];

        foreach ($headers as $index => $header) {
            $map[mb_strtolower(trim((string) $header))] = $index;
        }

        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($map));

        if ($missing !== []) {
            throw new RuntimeException('Al CSV le faltan columnas de SEPOMEX: '.implode(', ', $missing).'.');
        }

        return $map;
    }

    /**
     * Una línea del CSV como fila de `mx_postal_codes`, o `null` si no sirve.
     *
     * @param array<string, int> $columns
     * @return array{postal_code: string, settlement: string, settlement_type: string, municipality: string, city: string|null, state_code: string}|null
     */
    private function parseRow(string $line, string $delimiter, array $columns): ?array
    {
        $line = rtrim($line, "\r\n");

        if (trim($line) === '') {
            return null;
        }

        $values = str_getcsv($line, $delimiter, '"', '');

        $get = static function (string $column) use ($values, $columns): string {
            $index = $columns[$column] ?? null;

            return $index === null ? '' : trim($values[$index] ?? '');
        };

        // Los ceros de la izquierda se reponen: alguna copia del catálogo ha
        // pasado por una hoja de cálculo que leyó 01000 como el número 1000.
        $postalCode = str_pad($get('d_codigo'), 5, '0', STR_PAD_LEFT);
        $stateCode = str_pad($get('c_estado'), 2, '0', STR_PAD_LEFT);
        $settlement = $get('d_asenta');
        $municipality = $get('d_mnpio');
        $city = $get('d_ciudad');

        if (preg_match('/^\d{5}$/', $postalCode) !== 1) {
            return null;
        }

        if (preg_match('/^(0[1-9]|[12]\d|3[0-2])$/', $stateCode) !== 1) {
            return null;
        }

        if ($settlement === '' || $municipality === '') {
            return null;
        }

        return [
            'postal_code' => $postalCode,
            'settlement' => $settlement,
            // El tipo es NOT NULL porque forma parte del índice único; cuando el
            // archivo lo deja vacío se guarda 'Colonia', que es lo que es en el
            // 90 % de las filas del catálogo.
            'settlement_type' => $get('d_tipo_asenta') !== '' ? $get('d_tipo_asenta') : 'Colonia',
            'municipality' => $municipality,
            'city' => $city !== '' ? $city : null,
            'state_code' => $stateCode,
        ];
    }

    /**
     * Clave de deduplicación **dentro del lote**.
     *
     * No es una optimización: SQLite y PostgreSQL rechazan un `ON CONFLICT DO
     * UPDATE` que afecte dos veces a la misma fila en la misma sentencia, y el
     * catálogo trae asentamientos repetidos con distinto `id_asenta_cpcons`. Sin
     * esto, la importación real revienta en el primer lote que los contenga.
     *
     * @param array{postal_code: string, settlement: string, settlement_type: string, municipality: string, city: string|null, state_code: string} $row
     */
    private function keyOf(array $row): string
    {
        return $row['postal_code'].'|'.$row['settlement'].'|'.$row['settlement_type'];
    }

    /**
     * ¿El archivo ya es UTF-8?
     *
     * Se miran los primeros 64 KB y no el archivo entero: son catorce megas y la
     * pregunta se responde con la primera secuencia acentuada, que en un
     * catálogo de nombres de colonia llega en la segunda línea.
     */
    private function isUtf8(string $path): bool
    {
        $sample = file_get_contents($path, false, null, 0, 65536);

        if ($sample === false) {
            return true;
        }

        return mb_check_encoding($sample, 'UTF-8');
    }
}
