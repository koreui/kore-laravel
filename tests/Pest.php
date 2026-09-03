<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| `Tests\TestCase::setUp()` ya llama a `withoutVite()`, así que los tests no
| necesitan assets compilados. No lo repitas aquí con un `beforeEach`.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Tests dentro de cada módulo (app/Modules/{X}/Tests/{Feature|Unit})
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Unit');

// tests/Arch no extiende TestCase: los arch tests son estáticos, no bootean la
// aplicación ni tocan la base de datos.

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Cierra la transacción que abre `RefreshDatabase` para que el test sea dueño
 * del esquema.
 *
 * `RefreshDatabase` envuelve cada test en una transacción y la revierte al
 * terminar. Eso funciona para datos, pero **no** para los tests que ejercitan
 * el propio ciclo de migración: `migrate:fresh` sobre SQLite `:memory:` acaba
 * en `VACUUM main`, y SQLite no permite un `VACUUM` dentro de una transacción
 * («cannot VACUUM from within a transaction»).
 *
 * Al salir de la transacción, el `tearDown` de `RefreshDatabase` detecta que la
 * conexión ya no está transaccionada y marca `RefreshDatabaseState::$migrated`
 * como `false`: el siguiente test vuelve a correr `migrate:fresh` y arranca con
 * la base limpia. Es decir, ensuciar el esquema aquí no contamina a nadie.
 *
 * Sólo la usan los tests que migran de verdad: `MigrationsAreReversibleTest` y
 * `CleanInstallTest`.
 */
function releaseRefreshDatabaseTransaction(): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

/**
 * Arranca la aplicación con otras variables de entorno y ejecuta el callback.
 *
 * `Env::getRepository()->set()` a secas NO sirve: el repositorio de Dotenv es
 * inmutable, y en el siguiente `refreshApplication()` la recarga del `.env`
 * vuelve a pisar cualquier variable que hubiera venido de ahí (las apunta en su
 * mapa `loaded`). Lo único que respeta es una variable «definida desde fuera»,
 * es decir, presente en `$_ENV` / `$_SERVER` / `putenv()` sin estar en ese mapa.
 * Por eso aquí se hace en dos pasos: `clear()` la saca del mapa (y de los
 * adaptadores) y después se escribe directamente en los tres. Así el test da el
 * mismo resultado tenga el desarrollador la variable en su `.env` o no.
 *
 * Al terminar —también si el callback o el arranque lanzan— se restaura el valor
 * anterior y se vuelve a arrancar la aplicación para que el test siguiente no
 * herede nada.
 *
 * @param array<string, string> $variables
 */
function withEnvironment(array $variables, Closure $callback): void
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        writeRawEnvVariable($name, $value);
    }

    try {
        test()->refreshApplication();

        $callback();
    } finally {
        foreach ($previous as $name => $value) {
            writeRawEnvVariable($name, $value === false ? null : $value);
        }

        test()->refreshApplication();
    }
}

/**
 * Escribe (o borra, con `null`) una variable en los tres adaptadores de Dotenv.
 *
 * El borrado copia `$_ENV` en una variable local en vez de hacer
 * `unset($_ENV[$name])`: el `EnvVariableToEnvHelperRector` de rector-laravel
 * reescribe cualquier lectura de `$_ENV[...]` como `Env::get(...)`, y no
 * distingue la que está dentro de un `unset`.
 */
function writeRawEnvVariable(string $name, ?string $value): void
{
    Env::getRepository()->clear($name);

    if ($value === null) {
        $server = $_SERVER;
        $env = $_ENV;

        unset($server[$name], $env[$name]);

        $_SERVER = $server;
        $_ENV = $env;

        putenv($name);

        return;
    }

    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}
