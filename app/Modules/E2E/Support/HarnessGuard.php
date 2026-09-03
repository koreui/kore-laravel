<?php

declare(strict_types=1);

namespace App\Modules\E2E\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Los tres candados del harness de pruebas.
 *
 * El harness crea usuarios con el rol que le pidan, se salta el formulario de
 * login y lee el buzón de correo. Eso es exactamente lo que una suite E2E
 * necesita y exactamente lo que jamás puede existir en producción, así que no
 * basta con un flag: tienen que darse las **tres** condiciones a la vez.
 *
 *   1. `E2E_HARNESS=true` — encendido explícito (`kore-app.e2e.harness`).
 *   2. Entorno en la lista blanca (`e2e`, `testing`, `local`) — nunca
 *      `production`, nunca `staging`.
 *   3. Base de datos de pruebas: su nombre contiene «e2e» o «test», o es
 *      `:memory:`.
 *
 * El tercero es el que de verdad protege. Un flag se enciende por error en el
 * `.env` de un servidor —copiar y pegar es barato—, y un entorno se puede
 * llamar `local` en una máquina que no lo es. Lo que no pasa por accidente es
 * que la base de producción se llame `algo_e2e`. Mientras la conexión apunte a
 * la base real, el harness sigue muerto.
 *
 * `:memory:` cuenta como base de pruebas porque lo es por definición: vive en
 * el proceso y muere con él. Es la que usa `phpunit.xml`, y sin esta rama los
 * tests de Pest no podrían ejercitar el harness encendido.
 *
 * {@see reasons()} devuelve qué candado falla, en español y para leerlo un
 * humano: es lo que convierte «el harness no responde» en «la base se llama
 * kore_prod».
 */
final class HarnessGuard
{
    /**
     * Entornos donde el harness puede existir.
     *
     * Es una constante y no una clave de config a propósito: un toggle que se
     * puede ampliar desde el `.env` deja de ser un candado. Para añadir un
     * entorno hay que tocar el código, y eso se revisa.
     *
     * @var list<string>
     */
    public const array ENVIRONMENTS = ['e2e', 'testing', 'local'];

    /** Trozos que delatan una base de pruebas. */
    private const array TEST_DATABASE_NEEDLES = ['e2e', 'test'];

    /** ¿Se dan los tres candados? */
    public static function allows(): bool
    {
        return self::reasons() === [];
    }

    /**
     * Candados que NO se cumplen, uno por línea. Vacío significa «adelante».
     *
     * @return list<string>
     */
    public static function reasons(): array
    {
        $reasons = [];

        if (! self::flagIsOn()) {
            $reasons[] = 'El toggle kore-app.e2e.harness está apagado (E2E_HARNESS).';
        }

        if (! App::environment(self::ENVIRONMENTS)) {
            $reasons[] = sprintf(
                'El entorno «%s» no está en la lista blanca del harness (%s).',
                App::environment(),
                implode(', ', self::ENVIRONMENTS),
            );
        }

        if (! self::isTestDatabase()) {
            $reasons[] = sprintf(
                'La base «%s» no parece de pruebas: su nombre debe contener «e2e» o «test», o ser «:memory:».',
                self::databaseName(),
            );
        }

        return $reasons;
    }

    /** ¿La conexión configurada apunta a una base de pruebas? */
    public static function isTestDatabase(): bool
    {
        $database = self::databaseName();

        // SQLite en memoria: efímera por definición, muere con el proceso. Es
        // la que usa phpunit.xml.
        if ($database === ':memory:') {
            return true;
        }

        if ($database === '') {
            return false;
        }

        return Str::contains(Str::lower(basename($database)), self::TEST_DATABASE_NEEDLES);
    }

    /**
     * Nombre de la base de la conexión por defecto.
     *
     * Se lee de la **config** y no de `DB::connection()->getDatabaseName()`
     * para no despertar al manejador de base de datos: esto lo llama el
     * `boot()` del provider, en cada petición y en cada comando de artisan,
     * incluso cuando el harness está apagado.
     *
     * En sqlite la clave `database` es la ruta del archivo
     * (`database/e2e.sqlite`) o `:memory:`; en mysql y pgsql es el nombre del
     * esquema. Las dos formas se comparan igual: `basename()` deja
     * `e2e.sqlite` y `kore_e2e` intactos.
     */
    public static function databaseName(): string
    {
        $connection = Config::string('database.default');

        $database = Config::get("database.connections.{$connection}.database");

        return is_string($database) ? $database : '';
    }

    private static function flagIsOn(): bool
    {
        return (bool) config('kore-app.e2e.harness', false);
    }
}
