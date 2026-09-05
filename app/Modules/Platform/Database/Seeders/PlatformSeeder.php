<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database\Seeders;

use App\Core\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El permiso del módulo Platform, sembrado por el propio módulo.
 *
 * `settings.manage` ya lo produce `ModulesSeeder` a partir de la fila `settings`
 * de la tabla `modules` (y `Module::specialPermissions()`), así que en una
 * instalación de fábrica esto no cambia nada: es idempotente y se limita a
 * asegurar lo que ya está.
 *
 * Existe por el derivado. `ModulesSeeder` es «el catálogo de módulos de tu
 * producto» y lo primero que hace un proyecto hijo es recortarlo a los suyos;
 * cuando eso pasa, el permiso de una pantalla que sí sigue existiendo —los
 * ajustes los tiene toda instalación— se va con el recorte, y el síntoma es un
 * 403 en `/settings` para el administrador. Este seeder lo repone.
 *
 * No importa nada de `App\Modules\Auth` (R5): trabaja con las clases de
 * spatie/laravel-permission, que son de vendor, y con el enum de roles de
 * `Core`. Lo llama `DatabaseSeeder` —que no es de ningún módulo— justo después
 * de `ModulesSeeder`.
 */
final class PlatformSeeder extends Seeder
{
    public const string PERMISSION = 'settings.manage';

    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION],
            ['guard_name' => 'web'],
        );

        /*
         * Superadmin y administrador, y nadie más. El superadmin lo tendría
         * igual por el `Gate::before` de `AuthModuleServiceProvider`, pero se
         * le asigna de verdad por lo mismo que hace `ModulesSeeder`: para que
         * un `@can` siga funcionando si algún día alguien quita ese gate.
         */
        foreach ([SystemRole::Superadmin, SystemRole::Admin] as $role) {
            Role::query()
                ->where('name', '=', $role->value)
                ->where('guard_name', '=', 'web')
                ->first()
                ?->givePermissionTo($permission);
        }
    }
}
