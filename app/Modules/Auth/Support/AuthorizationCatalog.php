<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Core\Contracts\AuthorizationCatalog as AuthorizationCatalogContract;
use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\PermissionOptionData;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Spatie\Permission\Guard;

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
     * Permisos que otorga un rol.
     *
     * El guard sale de `Guard::getDefaultName(User::class)` y **no** de
     * `config('auth.defaults.guard')`. La diferencia no es cosmética: la primera
     * pregunta «¿bajo qué guard viven los roles de este modelo?» y responde
     * `web` mirando el `provider` de cada guard; la segunda pregunta «¿qué guard
     * está usando la aplicación ahora mismo?», y ahí cabe una sorpresa.
     *
     * **La cicatriz.** `AuthManager::shouldUse()` —que es lo que llama
     * `auth:sanctum`, y también `Sanctum::actingAs()` en un test— **escribe**
     * `config(['auth.defaults.guard' => 'sanctum'])`. Los roles se siembran con
     * `guard_name = 'web'`, así que en cualquier petición de la API este `where`
     * no encontraba ninguna fila y el método devolvía `[]`. Y un array vacío
     * aquí no rompe nada visible: hace que `GrantableRole` no encuentre ningún
     * permiso «que el actor no tenga» y deje pasar cualquier rol. R26 —nadie
     * asigna un rol más poderoso que él mismo— quedaba desactivada en silencio
     * justo en el canal donde no hay una pantalla que lo delate. Descubierto al
     * escribir `ApiUsersTest` en la v2.2.0, con un `users.create` creando
     * administradores.
     *
     * Es la misma resolución que usa spatie por dentro para `hasPermissionTo()`,
     * que por eso sí funcionaba: `Guard::getNames()` busca el guard cuyo
     * `provider` apunta a este modelo, y sólo cae al default si no hay ninguno.
     *
     * @return array<int, string>
     */
    public function permissionsForRole(string $role): array
    {
        $model = Role::query()
            ->where('name', '=', $role)
            ->where('guard_name', '=', Guard::getDefaultName(User::class))
            ->first();

        if (! $model instanceof Role) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = $model->permissions->pluck('name')->all();

        return $names;
    }
}
