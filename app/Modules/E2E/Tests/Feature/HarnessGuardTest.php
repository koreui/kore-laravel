<?php

declare(strict_types=1);

use App\Modules\E2E\Support\HarnessGuard;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| Los tres candados del harness
|--------------------------------------------------------------------------
|
| Cada candado se prueba por separado y en negativo: abiertos los otros dos, se
| cierra el que toca y el harness tiene que seguir muerto. Un test que sólo
| comprobara el caso feliz pasaría igual con dos candados de adorno.
|
| Los tres se leen de la config y del entorno detectado, así que no hace falta
| rearrancar la aplicación (ver docs/patterns/test-con-otro-entorno.md): basta
| `Config::set()` y `App::detectEnvironment()`.
|
| Ojo con la conexión: la suite corre dentro de la transacción de
| `RefreshDatabase` sobre la SQLite `:memory:`, y `database.default` es lo que
| esa transacción usa para revertir al terminar. Por eso los tests que la
| cambian lo hacen a través de `harnessWithDatabase()`, que la restaura en un
| `finally` aunque la aserción falle.
|
*/

/** Deja los tres candados abiertos; cada test cierra el suyo. */
function harnessAllOpen(): void
{
    Config::set('kore-app.e2e.harness', true);
    App::detectEnvironment(fn (): string => 'e2e');
}

/**
 * Ejecuta el callback con otra conexión por defecto y la restaura al salir.
 */
function harnessWithDatabase(string $connection, string $database, Closure $assert): void
{
    $previous = Config::string('database.default');

    Config::set("database.connections.{$connection}.database", $database);
    Config::set('database.default', $connection);

    try {
        $assert();
    } finally {
        Config::set('database.default', $previous);
    }
}

it('allows the harness when the three padlocks are open', function (): void {
    harnessAllOpen();

    harnessWithDatabase('sqlite', 'database/e2e.sqlite', function (): void {
        expect(HarnessGuard::allows())->toBeTrue()
            ->and(HarnessGuard::reasons())->toBe([]);
    });
});

it('refuses with the toggle off', function (): void {
    harnessAllOpen();
    Config::set('kore-app.e2e.harness', false);

    expect(HarnessGuard::allows())->toBeFalse()
        ->and(HarnessGuard::reasons())->toHaveCount(1)
        ->and(HarnessGuard::reasons()[0])->toContain('E2E_HARNESS');
});

it('refuses in an environment outside the whitelist', function (): void {
    harnessAllOpen();
    App::detectEnvironment(fn (): string => 'production');

    expect(HarnessGuard::allows())->toBeFalse()
        ->and(HarnessGuard::reasons())->toHaveCount(1)
        ->and(HarnessGuard::reasons()[0])->toContain('production')
        ->and(HarnessGuard::reasons()[0])->toContain('lista blanca');
});

it('refuses against a database that does not look like a test one', function (): void {
    harnessAllOpen();

    harnessWithDatabase('mysql', 'kore_prod', function (): void {
        expect(HarnessGuard::allows())->toBeFalse()
            ->and(HarnessGuard::reasons())->toHaveCount(1)
            ->and(HarnessGuard::reasons()[0])->toContain('kore_prod');
    });
});

it('names every padlock that fails, not just the first', function (): void {
    Config::set('kore-app.e2e.harness', false);
    App::detectEnvironment(fn (): string => 'production');

    harnessWithDatabase('mysql', 'kore_prod', function (): void {
        expect(HarnessGuard::reasons())->toHaveCount(3);
    });
});

/*
 * Formas de base que SÍ pasan el tercer candado. `:memory:` es la de
 * `phpunit.xml` —efímera por definición—, `database/e2e.sqlite` la de la suite
 * E2E y `kore_testing` la de un MySQL de pruebas. En sqlite la clave
 * `database` es una ruta y en mysql/pgsql un nombre de esquema; `basename()`
 * deja las dos formas comparables.
 */
it('accepts the databases that are test ones', function (string $connection, string $database): void {
    harnessAllOpen();

    harnessWithDatabase($connection, $database, function () use ($database): void {
        expect(HarnessGuard::isTestDatabase())->toBeTrue()
            ->and(HarnessGuard::databaseName())->toBe($database)
            ->and(HarnessGuard::allows())->toBeTrue();
    });
})->with([
    'sqlite en memoria' => ['sqlite', ':memory:'],
    'sqlite de la suite' => ['sqlite', 'database/e2e.sqlite'],
    'mysql de pruebas' => ['mysql', 'kore_testing'],
    'pgsql de pruebas' => ['pgsql', 'kore_e2e'],
]);

it('rejects the databases that are not', function (string $database): void {
    harnessAllOpen();

    harnessWithDatabase('mysql', $database, function (): void {
        expect(HarnessGuard::isTestDatabase())->toBeFalse()
            ->and(HarnessGuard::allows())->toBeFalse();
    });
})->with([
    'producción' => ['kore_prod'],
    'la de siempre' => ['kore_laravel'],
    'sin nombre' => [''],
]);

/*
 * El entorno de la suite (`testing`) y su base `:memory:` bastan para los dos
 * últimos candados: es lo que permite que HarnessRoutesTest ejercite el
 * harness encendido sin inventarse nada. Lo único que falta es el flag, y por
 * eso `phpunit.xml` lo fuerza a false.
 */
it('counts the suite environment and its in-memory database as valid', function (): void {
    expect($this->app->environment())->toBe('testing')
        ->and(HarnessGuard::ENVIRONMENTS)->toContain('testing')
        ->and(HarnessGuard::databaseName())->toBe(':memory:')
        ->and(HarnessGuard::isTestDatabase())->toBeTrue()
        ->and(config('kore-app.e2e.harness'))->toBeFalse()
        ->and(HarnessGuard::allows())->toBeFalse();
});
