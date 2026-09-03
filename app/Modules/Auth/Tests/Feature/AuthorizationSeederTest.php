<?php

declare(strict_types=1);

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

it('seeds modules, permissions and roles', function (): void {
    $this->seed(ModulesSeeder::class);

    expect(Module::count())->toBeGreaterThanOrEqual(3);
    expect(Permission::where('name', 'users.view')->exists())->toBeTrue();
    expect(SpatieRole::where('name', Role::ADMIN)->exists())->toBeTrue();
    expect(SpatieRole::where('name', Role::SUPERADMIN)->exists())->toBeTrue();
});

it('admin role gets all permissions', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = SpatieRole::where('name', Role::ADMIN)->first();

    expect($admin->permissions->count())->toBe(Permission::count());
});

it('user role only has dashboard.view', function (): void {
    $this->seed(ModulesSeeder::class);

    $user = SpatieRole::where('name', Role::USER)->first();

    expect($user->permissions->pluck('name')->all())->toBe(['dashboard.view']);
});

it('superadmin bypasses gate via Gate::before', function (): void {
    $this->seed(ModulesSeeder::class);

    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    expect($superadmin->can('any.permission.even.unregistered'))->toBeTrue();
});

it('regenerate-permissions command syncs permissions to admins', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $admin->syncPermissions([]);

    expect($admin->getDirectPermissions())->toHaveCount(0);

    $this->artisan('kore:regenerate-permissions')->assertSuccessful();

    $admin->refresh();
    expect($admin->getDirectPermissions()->count())->toBe(Permission::count());
});

it('Module generates standard CRUD permissions', function (): void {
    $module = Module::create([
        'name' => 'Productos',
        'slug' => 'productos',
        'roles' => [],
        'active' => true,
    ]);

    $values = collect($module->permissions)->pluck('value')->all();

    expect($values)->toBe([
        'productos.view',
        'productos.create',
        'productos.edit',
        'productos.delete',
    ]);
});

it('ModulesCollection flatPermissions returns all permission strings', function (): void {
    $this->seed(ModulesSeeder::class);

    $flat = Module::where('active', true)->get()->flatPermissions();

    expect($flat)->toContain('users.view', 'dashboard.view', 'roles.delete');
});

/*
|--------------------------------------------------------------------------
| El guard con el que el catálogo busca los roles
|--------------------------------------------------------------------------
|
| `AuthManager::shouldUse()` —lo que llama `auth:sanctum`, y también
| `Sanctum::actingAs()`— ESCRIBE `config(['auth.defaults.guard' => 'sanctum'])`.
| Los roles se siembran con `guard_name = 'web'`, así que un catálogo que
| filtrara por el default de la aplicación devolvía `[]` en cada petición de la
| API, y `GrantableRole` dejaba pasar cualquier rol: R26 desactivada en silencio
| justo donde no hay pantalla que lo delate (v2.2.0).
|
*/

it('resuelve los permisos de un rol también bajo el guard sanctum', function (): void {
    $this->seed(ModulesSeeder::class);

    $catalog = resolve(AuthorizationCatalog::class);

    $conWeb = $catalog->permissionsForRole(SystemRole::Admin->value);

    expect($conWeb)->not->toBeEmpty();

    auth()->shouldUse('sanctum');

    expect(config('auth.defaults.guard'))->toBe('sanctum')
        ->and($catalog->permissionsForRole(SystemRole::Admin->value))->toBe($conWeb);
});
