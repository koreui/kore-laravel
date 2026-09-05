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
final class ModulesSeeder extends Seeder
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
            /*
             * Invitaciones. Se siembra SIEMPRE, también con
             * `AUTH_INVITATIONS=false`, por lo mismo que las tablas de un módulo
             * apagado se migran igual: el catálogo de permisos es forma, no
             * capacidad (`docs/architecture/toggles.md`). Si dependiera del
             * toggle, encenderlo en producción exigiría además acordarse de
             * volver a sembrar los permisos para que alguien pudiera entrar a
             * la pantalla — justo cuando ya hay tráfico.
             *
             * Su permiso, `invitations.manage`, lo declara
             * `Module::specialPermissions()`.
             */
            [
                'name' => 'Invitaciones',
                'slug' => 'invitations',
                'roles' => [Role::ADMIN],
                'active' => true,
            ],

            // Ajustes de la instalación (módulo Platform). Su único permiso,
            // `settings.manage`, se declara en Module::specialPermissions().
            [
                'name' => 'Ajustes',
                'slug' => 'settings',
                'roles' => [Role::ADMIN],
                'active' => true,
            ],

            // Webhooks no lleva CRUD de cuatro sino un único permiso especial,
            // declarado en Module::specialPermissions(): ver la lista de
            // endpoints ya enseña a qué sistemas se les cuenta lo que pasa
            // aquí dentro, y quien la ve puede leer el payload de cada
            // entrega. No hay un «sólo lectura» menos sensible que el resto.
            //
            // El módulo se siembra SIEMPRE, también con WEBHOOKS_ENABLED=false:
            // un toggle apaga rutas y comportamiento, no el catálogo de
            // permisos — igual que la migración de sus tablas.
            [
                'name' => 'Webhooks',
                'slug' => 'webhooks',
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

        // Superadmin: bypass total via Gate::before en AuthModuleServiceProvider.
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
