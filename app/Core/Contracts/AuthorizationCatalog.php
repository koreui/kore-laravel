<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\RoleOptionData;

/**
 * Catálogo de roles y permisos del sistema.
 *
 * Es la frontera entre el módulo que **define** la autorización (Auth, dueño de
 * `Role` y `Module`) y los módulos que sólo la **consumen** (Users y los que
 * vengan). Gracias a este contrato, `App\Modules\Users` no importa una sola
 * clase de `App\Modules\Auth` (regla 3 de CLAUDE.md).
 *
 * La implementación se bindea en `AuthModuleServiceProvider::register()`.
 */
interface AuthorizationCatalog
{
    /**
     * Roles que la UI puede ofrecer. Excluye superadmin.
     *
     * @return array<int, RoleOptionData>
     */
    public function assignableRoles(): array;

    /**
     * Sólo los nombres de {@see assignableRoles()}, para `Rule::in(...)`.
     *
     * @return array<int, string>
     */
    public function assignableRoleNames(): array;

    /**
     * Módulos activos con sus permisos, para la matriz del formulario.
     *
     * @return array<int, PermissionModuleData>
     */
    public function permissionModules(): array;

    /**
     * Permisos que otorga un rol, por nombre de permiso.
     *
     * Devuelve un array vacío si el rol no existe.
     *
     * @return array<int, string>
     */
    public function permissionsForRole(string $role): array;
}
