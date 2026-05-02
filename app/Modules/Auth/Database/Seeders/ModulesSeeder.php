<?php

declare(strict_types=1);

namespace App\Modules\Auth\Database\Seeders;

use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Source of truth de módulos, permisos y roles del sistema.
 *
 * Cada vez que agregas un módulo nuevo:
 * 1. Súmalo al array $modules de seedModules()
 * 2. Si tiene permisos no-CRUD, añadelos a Module::specialPermissions()
 * 3. Corre `php artisan kore:regenerate-permissions` (seeder + sync a admins)
 */
class ModulesSeeder extends Seeder
{
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedModules();
        $this->seedPermissions();
        $this->seedRoles();
    }

    private function seedModules(): void
    {
        $modules = [
            [
                'name' => 'Dashboard',
                'slug' => 'dashboard',
                'roles' => [Role::ADMIN, Role::USER],
                'active' => true,
            ],
            [
                'name' => 'Usuarios',
                'slug' => 'users',
                'roles' => [Role::ADMIN],
                'active' => true,
            ],
            [
                'name' => 'Roles',
                'slug' => 'roles',
                'roles' => [Role::ADMIN],
                'active' => true,
            ],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['slug' => $module['slug']],
                [
                    'name' => $module['name'],
                    'active' => $module['active'],
                    'roles' => $module['roles'],
                ],
            );
        }
    }

    private function seedPermissions(): void
    {
        $permissions = Module::where('active', true)->get()->flatPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web'],
            );
        }
    }

    private function seedRoles(): void
    {
        $allPermissions = Permission::all();

        // Superadmin: bypass total via Gate::before en AppServiceProvider.
        // Igual sincronizamos los permisos para que @can siga funcionando si
        // alguien quita el Gate::before accidentalmente.
        $superadmin = SpatieRole::firstOrCreate(
            ['name' => Role::SUPERADMIN, 'guard_name' => 'web'],
        );
        $superadmin->syncPermissions($allPermissions);

        // Administrador: acceso completo.
        $admin = SpatieRole::firstOrCreate(
            ['name' => Role::ADMIN, 'guard_name' => 'web'],
        );
        $admin->syncPermissions($allPermissions);

        // Usuario: sólo dashboard. Personaliza este array para tu app.
        $user = SpatieRole::firstOrCreate(
            ['name' => Role::USER, 'guard_name' => 'web'],
        );
        $user->syncPermissions(['dashboard.view']);
    }
}
