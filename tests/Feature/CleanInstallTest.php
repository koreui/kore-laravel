<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Instalación limpia
|--------------------------------------------------------------------------
|
| Reproduce lo que hacen `composer setup` y el entrypoint de Docker sobre una
| base recién creada: migrar y sembrar con los seeders reales, sin fakes. Es el
| único test que corre `DatabaseSeeder` de punta a punta; el resto de la suite
| siembra sólo `ModulesSeeder`.
|
*/

beforeEach(function (): void {
    releaseRefreshDatabaseTransaction();
});

/**
 * `PermissionRegistrar` cachea permisos y roles en memoria. Tras un
 * `migrate:fresh` la caché apunta a filas que ya no existen, así que se tira
 * antes de leer nada.
 */
function forgetPermissionCache(): void
{
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('installs from scratch with migrate:fresh --seed', function (): void {
    Artisan::call('migrate:fresh', ['--seed' => true]);

    forgetPermissionCache();

    // Lo que siembra ModulesSeeder: los tres módulos, sus permisos y los tres
    // roles del sistema.
    expect(Module::count())->toBe(3)
        ->and(SpatieRole::count())->toBe(3)
        ->and(Permission::count())->toBeGreaterThan(0);

    $admin = User::query()->where('email', 'admin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole(Role::ADMIN))->toBeTrue()
        ->and($admin->permissions()->count())->toBe(Permission::count());

    foreach (Permission::pluck('name') as $permission) {
        expect($admin->hasPermissionTo($permission))->toBeTrue("el admin no tiene {$permission}");
    }
});

it('is idempotent when seeding twice', function (): void {
    Artisan::call('migrate:fresh', ['--seed' => true]);
    Artisan::call('db:seed');

    forgetPermissionCache();

    // Ni duplica el admin ni multiplica módulos, roles o permisos.
    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1)
        ->and(Module::count())->toBe(3)
        ->and(SpatieRole::count())->toBe(3);

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($admin->permissions()->count())->toBe(Permission::count());
});

it('runs the e2e seeder on a clean database', function (): void {
    Artisan::call('migrate:fresh');
    Artisan::call('db:seed', ['--class' => E2eSeeder::class]);

    forgetPermissionCache();

    $accounts = [
        'superadmin@e2e.test' => Role::SUPERADMIN,
        'editor@e2e.test' => Role::USER,
        'viewer@e2e.test' => Role::USER,
        'member@e2e.test' => Role::USER,
    ];

    foreach ($accounts as $email => $role) {
        $user = User::query()->where('email', $email)->first();

        expect($user)->not->toBeNull("falta la cuenta {$email}")
            ->and($user->hasRole($role))->toBeTrue("{$email} no tiene el rol {$role}");
    }

    // Los permisos directos son lo que separa a editor de viewer y de member.
    $permissionsOf = fn (string $email): array => User::query()
        ->where('email', $email)
        ->firstOrFail()
        ->getDirectPermissions()
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($permissionsOf('editor@e2e.test'))->toBe(['users.create', 'users.edit', 'users.view'])
        ->and($permissionsOf('viewer@e2e.test'))->toBe(['users.view'])
        ->and($permissionsOf('member@e2e.test'))->toBe([]);
});
