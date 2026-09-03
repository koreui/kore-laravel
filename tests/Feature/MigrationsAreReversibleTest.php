<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| R29 · toda migración define down() — y ese down() funciona
|--------------------------------------------------------------------------
|
| `kore:arch:check --rule=R29` sólo lee el texto del archivo: comprueba que el
| método existe, no que haga algo. Este test lo ejecuta de verdad, incluidas las
| migraciones publicadas de vendor (pulse, permission, activity_log, health,
| one_time_passwords, features), a las que la v1.2.0 les añadió un `down()` que
| nadie había corrido nunca.
|
| Cubre las migraciones de `database/migrations/` y las de cada módulo, que su
| provider registra con `loadMigrationsFrom()`.
|
*/

/**
 * Tablas que `migrate` tiene que dejar aplicadas. No es la lista completa a
 * propósito: es una por origen —framework, módulo y cada paquete que publicó su
 * migración—, que es donde duele un `down()` mal escrito.
 */
const MIGRATION_SMOKE_TABLES = [
    'users',
    'sessions',
    'jobs',
    'modules',
    'permissions',
    'roles',
    'personal_access_tokens',
    'one_time_passwords',
    'activity_log',
    'health_check_result_history_items',
    'features',
    'pulse_values',
];

/**
 * Migraciones registradas (las del proyecto y las de los módulos) que todavía
 * no están aplicadas. Es lo mismo que muestra `migrate:status` como «Pending»,
 * pero sin tener que parsear la salida del comando.
 *
 * @return array<int, string>
 */
function pendingMigrationNames(): array
{
    $migrator = resolve(Migrator::class);

    $files = $migrator->getMigrationFiles(
        array_merge($migrator->paths(), [database_path('migrations')]),
    );

    return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
}

beforeEach(function (): void {
    releaseRefreshDatabaseTransaction();
});

it('rolls back every migration and migrates again', function (): void {
    Artisan::call('migrate:fresh');

    foreach (MIGRATION_SMOKE_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("falta la tabla {$table} después de migrate:fresh");
    }

    // `migrate:reset` corre el down() de todas, de la última a la primera.
    Artisan::call('migrate:reset');

    // `migrations` es el repositorio del propio migrador; `sqlite_sequence` la
    // crea SQLite sola cuando hay AUTOINCREMENT y no la borra ninguna migración.
    $left = array_values(array_diff(
        Schema::getTableListing(schemaQualified: false),
        ['migrations', 'sqlite_sequence'],
    ));

    expect($left)->toBeEmpty();

    Artisan::call('migrate');

    foreach (MIGRATION_SMOKE_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("falta la tabla {$table} después de volver a migrar");
    }

    expect(pendingMigrationNames())->toBeEmpty();
});

it('leaves no column behind after rolling back the users table additions', function (): void {
    Artisan::call('migrate:fresh');

    $ran = resolve(Migrator::class)->getRepository()->getRan();

    $index = null;

    foreach ($ran as $position => $migration) {
        if (str_contains($migration, 'add_two_factor_columns_to_users_table')) {
            $index = $position;

            break;
        }
    }

    expect($index)->not->toBeNull();

    // Deshacemos desde la última aplicada hasta ésta, incluida, para que el
    // down() del 2FA corra con la tabla `users` todavía en pie: es el caso
    // frágil, porque es el único dropColumn() del boilerplate y en SQLite
    // Laravel lo resuelve con ALTER TABLE ... DROP COLUMN nativo.
    Artisan::call('migrate:rollback', ['--step' => count($ran) - $index]);

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeFalse()
        ->and(Schema::hasColumns('users', ['name', 'email', 'password']))->toBeTrue();

    Artisan::call('migrate');

    expect(Schema::hasColumns('users', [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ]))->toBeTrue()
        ->and(pendingMigrationNames())->toBeEmpty();
});
