<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\Finder\Finder;

/**
 * Los toggles de `config/kore-app.php`, con su variable de `.env` y con quién
 * los lee.
 *
 * La lista de lectores es la misma idea que el check R11 de
 * `App\Core\Console\ArchCheckCommand::checkTogglesAreRead()`: búsqueda textual
 * de `kore-app.{clave}` por el repositorio. Está reimplementada a propósito y
 * no reutiliza el comando: acoplar el servidor MCP al linter obligaría a
 * cambiar los dos cada vez que uno evoluciona, y el linter sólo responde
 * «alguien lo lee: sí/no», que no es lo que necesita un agente.
 *
 * Nunca se devuelve el valor de una clave que huela a secreto: sólo si está
 * configurada o no.
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListTogglesTool extends Tool
{
    /**
     * Dónde se busca a los lectores de un toggle: donde vive el código que
     * decide. `database/` y `tests/` quedan fuera —sembrar o testear un toggle
     * no es leerlo—, pero los tests de un módulo sí aparecen, porque viven
     * dentro de `app/Modules/{X}/Tests` y la ruta los delata.
     *
     * @var list<string>
     */
    private const array READER_DIRECTORIES = ['app', 'bootstrap', 'config', 'resources', 'routes'];

    /**
     * Claves que NO son toggles de `kore-app` pero que encienden o apagan
     * capacidades del boilerplate, y que un agente confunde con toggles si no
     * se las nombra. Cada una: archivo de config donde vive la clave, y la nota
     * que explica por qué no está en `kore-app`.
     *
     * @var array<string, array{config: string, nota: string}>
     */
    private const array NON_TOGGLES = [
        'pulse.enabled' => [
            'config' => 'config/pulse.php',
            'nota' => 'Laravel Pulse se enciende desde su propio config, no desde kore-app.',
        ],
        'sentry.dsn' => [
            'config' => 'config/sentry.php',
            'nota' => 'Sentry se activa por la simple presencia del DSN: no hay toggle booleano.',
        ],
        'health.secret_token' => [
            'config' => 'config/health.php',
            'nota' => 'Protege /health/json. Ojo: con el token vacío el middleware del paquete deja pasar todo.',
        ],
        'kore-app.docs.enabled' => [
            'config' => 'config/kore-app.php',
            'nota' => 'Reservado: sólo aparece si algún día se añade al config.',
        ],
    ];

    /**
     * Fragmentos que marcan una clave como secreta. Se comparan contra la clave
     * completa, en minúsculas.
     *
     * @var list<string>
     */
    private const array SECRET_HINTS = ['token', 'password', 'secret', 'key', 'dsn', 'passphrase', 'credential'];

    protected string $name = 'kore-list-toggles';

    protected string $title = 'Toggles del boilerplate';

    protected string $description = 'Los toggles de config/kore-app.php: clave, valor actual, variable de .env que la alimenta, valor por defecto y qué archivos la leen (R11: un toggle que no lee nadie miente sobre lo que hace el boilerplate). Incluye también las claves que encienden capacidades pero no viven en kore-app (Pulse, Sentry, el token de /health). Nunca devuelve el valor de un secreto, sólo si está configurado.';

    public function handle(Request $request): Response
    {
        /** @var array<string, mixed> $toggles */
        $toggles = (array) Config::get('kore-app', []);

        $sources = $this->environmentVariables(base_path().'/config/kore-app.php');
        $haystack = $this->readerContents();

        $rows = [];

        foreach (array_keys(Arr::dot($toggles)) as $key) {
            $key = (string) $key;
            $source = $sources[$key] ?? null;

            $rows[] = [
                'clave' => 'kore-app.'.$key,
                'valor' => $this->presentableValue($key, Config::get('kore-app.'.$key)),
                'env' => $source['env'] ?? null,
                'por_defecto' => $source['por_defecto'] ?? null,
                'leido_por' => $this->readers('kore-app.'.$key, $haystack),
            ];
        }

        return Response::json([
            'config' => 'config/kore-app.php',
            'total' => count($rows),
            'toggles' => $rows,
            'no_toggles' => $this->nonToggles(),
            'notas' => [
                'R11: un toggle sólo existe si alguien lo lee; «leido_por» vacío es una violación, no una curiosidad.',
                'R10: con el toggle apagado, el ServiceProvider del módulo hace return temprano y no registra nada.',
                'R12: un config/*.php no puede leer otro (se cargan en orden alfabético); si un paquete tiene que reaccionar a kore-app, se muta su config desde el register() del provider.',
                'Los valores de claves sensibles se sustituyen por «configurado» / «sin configurar»: este servidor no devuelve secretos.',
            ],
        ]);
    }

    /**
     * Las claves que encienden capacidades sin ser toggles de `kore-app`.
     *
     * @return list<array<string, mixed>>
     */
    private function nonToggles(): array
    {
        $rows = [];

        foreach (self::NON_TOGGLES as $key => $meta) {
            if (! Config::has($key)) {
                continue;
            }

            $file = base_path().'/'.$meta['config'];
            $source = $this->environmentVariables($file)[Str::after($key, '.')] ?? null;

            $rows[] = [
                'clave' => $key,
                'valor' => $this->presentableValue($key, Config::get($key)),
                'env' => $source['env'] ?? null,
                'config' => $meta['config'],
                'nota' => $meta['nota'],
            ];
        }

        return $rows;
    }

    /**
     * El valor tal cual, salvo que la clave huela a secreto.
     */
    private function presentableValue(string $key, mixed $value): mixed
    {
        if (! $this->isSecret($key)) {
            return $value;
        }

        return $value === null || $value === '' ? 'sin configurar' : 'configurado';
    }

    private function isSecret(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SECRET_HINTS as $hint) {
            if (str_contains($key, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Archivos que mencionan `config('kore-app.{clave}')`, en ruta relativa.
     *
     * @param array<string, string> $haystack ruta relativa => contenido
     * @return list<string>
     */
    private function readers(string $needle, array $haystack): array
    {
        $found = [];

        foreach ($haystack as $path => $contents) {
            if (str_contains($contents, $needle)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Contenido de todo el PHP y las Blade donde puede vivir un lector, menos
     * el propio `config/kore-app.php` (que se declara, no se lee).
     *
     * @return array<string, string>
     */
    private function readerContents(): array
    {
        $root = base_path();

        $directories = array_values(array_filter(
            array_map(fn (string $directory): string => $root.'/'.$directory, self::READER_DIRECTORIES),
            is_dir(...),
        ));

        if ($directories === []) {
            return [];
        }

        $finder = (new Finder)->files()->in($directories)->name(['*.php', '*.blade.php'])->sortByName();

        $contents = [];

        foreach ($finder as $file) {
            $path = $file->getRealPath();

            if ($path === false || $path === $root.'/config/kore-app.php') {
                continue;
            }

            $contents[$this->relative($path, $root)] = $file->getContents();
        }

        return $contents;
    }

    /**
     * Mapa `clave.puntuada` => variable de `.env` y valor por defecto, leyendo
     * el archivo de config en crudo.
     *
     * Es un parser de una sola pasada, deliberadamente tonto: reconoce
     * `'seccion' => [` para abrir un nivel y `'clave' => ... env('VAR', x)` para
     * la hoja. Sirve porque los `config/*.php` del proyecto los formatea Pint y
     * tienen esa forma. Gana el primer match de cada clave, que en un config
     * bien escrito es el de menos anidamiento.
     *
     * @return array<string, array{env: string, por_defecto: string|null}>
     */
    private function environmentVariables(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $section = null;
        $map = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if (preg_match("/^'([A-Za-z0-9_.\-]+)'\s*=>\s*\[$/", $line, $matches) === 1) {
                $section = $matches[1];

                continue;
            }

            if ($line === '],' || $line === '];') {
                $section = null;

                continue;
            }

            if (preg_match("/^'([A-Za-z0-9_.\-]+)'\s*=>.*?\benv\('([A-Z0-9_]+)'(?:\s*,\s*([^)]*))?\)/", $line, $matches) !== 1) {
                continue;
            }

            $key = $section === null ? $matches[1] : $section.'.'.$matches[1];

            if (isset($map[$key])) {
                continue;
            }

            $default = isset($matches[3]) ? trim($matches[3]) : '';

            $map[$key] = [
                'env' => $matches[2],
                'por_defecto' => $default === '' ? null : $default,
            ];
        }

        return $map;
    }

    private function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root.'/')
            ? mb_substr($path, mb_strlen($root) + 1)
            : $path;
    }
}
