<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Core\Contracts\AuthorizationCatalog as AuthorizationCatalogContract;
use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\PermissionOptionData;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Enums\SystemRole;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;

/**
 * Implementación del catálogo de autorización sobre los modelos del módulo
 * Auth (`Role` + `Module`).
 *
 * Es el único sitio del boilerplate que traduce esos modelos a los DTOs de
 * `App\Core\Data\Authorization`; el resto de módulos habla sólo con el
 * contrato. Se bindea en `AuthModuleServiceProvider::register()`.
 */
final class AuthorizationCatalog implements AuthorizationCatalogContract
{
    /**
     * @return array<int, RoleOptionData>
     */
    public function assignableRoles(): array
    {
        return array_map(
            fn (SystemRole $role): RoleOptionData => new RoleOptionData(
                value: $role->value,
                label: $role->label(),
            ),
            SystemRole::assignable(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function assignableRoleNames(): array
    {
        return array_map(
            fn (RoleOptionData $role): string => $role->value,
            $this->assignableRoles(),
        );
    }

    /**
     * @return array<int, PermissionModuleData>
     */
    public function permissionModules(): array
    {
        return array_map(
            fn (array $module): PermissionModuleData => new PermissionModuleData(
                module: $module['module'],
                permissions: array_map(
                    fn (array $permission): PermissionOptionData => new PermissionOptionData(
                        value: $permission['value'],
                        label: $permission['label'],
                    ),
                    $module['permissions'],
                ),
                roles: $module['roles'],
            ),
            Module::query()->where('active', '=', true)->get()->permissionsToArray(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForRole(string $role): array
    {
        $model = Role::query()
            ->where('name', '=', $role)
            ->where('guard_name', '=', config('auth.defaults.guard'))
            ->first();

        if (! $model instanceof Role) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = $model->permissions->pluck('name')->all();

        return $names;
    }
}
