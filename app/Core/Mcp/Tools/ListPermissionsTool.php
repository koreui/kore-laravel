<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\PermissionOptionData;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Enums\SystemRole;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * El catálogo de autorización: roles del sistema, roles asignables y la matriz
 * de módulos con sus permisos.
 *
 * Todo pasa por `App\Core\Contracts\AuthorizationCatalog`, que es exactamente
 * como Users habla con Auth sin importarlo (R5 · R6). Esta herramienta no
 * conoce `Role` ni `Module`, igual que no los conoce ningún otro consumidor.
 *
 * La matriz sí necesita base de datos (los módulos son filas). Si la base no
 * responde —un agente preguntando sobre un clon recién hecho, sin migrar—, se
 * devuelve lo que sí es estático (el enum de roles del sistema y los roles
 * asignables) más un aviso, en vez de reventar.
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListPermissionsTool extends Tool
{
    protected string $name = 'kore-list-permissions';

    protected string $title = 'Roles y permisos';

    protected string $description = 'El catálogo de autorización del boilerplate: los roles del sistema (enum SystemRole), los roles que la UI puede asignar, la matriz de módulos con sus permisos {slug}.{accion} y los permisos que otorga cada rol, todo vía el contrato App\Core\Contracts\AuthorizationCatalog. Si la base de datos responde, añade qué permisos están realmente sembrados. Úsala antes de escribir un can(), una Policy o un test de autorización.';

    public function __construct(private readonly AuthorizationCatalog $catalog) {}

    public function handle(Request $request): Response
    {
        $warnings = [];
        $modules = null;
        $byRole = null;
        $seeded = null;

        try {
            $modules = $this->permissionModules();
            $byRole = $this->permissionsByRole();
            $seeded = $this->seededPermissions();
        } catch (Throwable $throwable) {
            $warnings[] = 'La matriz de módulos y permisos vive en la base de datos y no se pudo leer ('
                .$throwable::class.': '.$throwable->getMessage()
                .'). Lo demás es el catálogo estático. Prueba con `php artisan migrate --seed`.';
        }

        return Response::json([
            'contrato' => AuthorizationCatalog::class,
            'roles_del_sistema' => $this->systemRoles(),
            'roles_asignables' => array_map(
                fn (RoleOptionData $role): array => ['valor' => $role->value, 'etiqueta' => $role->label],
                $this->catalog->assignableRoles(),
            ),
            'modulos' => $modules,
            'permisos_por_rol' => $byRole,
            'permisos_sembrados' => $seeded,
            'avisos' => $warnings,
            'notas' => [
                'R25: la Policy del módulo es el único punto de decisión; los permisos son el dato, no la regla.',
                'R26: nadie concede un rol ni un permiso que no tiene. El superadmin es la única excepción (bypass por Gate::before).',
                'Los permisos tienen el formato {slug_del_modulo}.{accion}; las acciones por defecto son view, create, edit y delete.',
            ],
        ]);
    }

    /**
     * Los roles del enum de Core, que existen exista o no la base de datos.
     *
     * @return list<array<string, mixed>>
     */
    private function systemRoles(): array
    {
        $assignable = $this->catalog->assignableRoleNames();

        return array_map(
            fn (SystemRole $role): array => [
                'caso' => $role->name,
                'valor' => $role->value,
                'etiqueta' => $role->label(),
                'asignable_en_ui' => in_array($role->value, $assignable, true),
            ],
            SystemRole::cases(),
        );
    }

    /**
     * La matriz de la UI: cada módulo activo, sus permisos y los roles que los
     * pre-seleccionan.
     *
     * @return list<array<string, mixed>>
     */
    private function permissionModules(): array
    {
        return array_values(array_map(
            fn (PermissionModuleData $module): array => [
                'modulo' => $module->module,
                'roles' => $module->roles,
                'permisos' => array_map(
                    fn (PermissionOptionData $permission): array => [
                        'valor' => $permission->value,
                        'etiqueta' => $permission->label,
                    ],
                    $module->permissions,
                ),
            ],
            $this->catalog->permissionModules(),
        ));
    }

    /**
     * Permisos que otorga cada rol del sistema, por nombre de permiso.
     *
     * @return array<string, array<int, string>>
     */
    private function permissionsByRole(): array
    {
        $byRole = [];

        foreach (SystemRole::cases() as $role) {
            $byRole[$role->value] = $this->catalog->permissionsForRole($role->value);
        }

        return $byRole;
    }

    /**
     * Los permisos que existen de verdad en la tabla de
     * spatie/laravel-permission.
     *
     * `Permission` es una clase de vendor, no de `App\Modules`, así que Core
     * puede usarla sin romper R6.
     *
     * @return array<string, mixed>
     */
    private function seededPermissions(): array
    {
        $table = (new Permission)->getTable();

        if (! Schema::hasTable($table)) {
            return ['tabla' => $table, 'existe' => false, 'total' => 0, 'nombres' => []];
        }

        /** @var array<int, string> $names */
        $names = Permission::query()->orderBy('name')->pluck('name')->all();

        return ['tabla' => $table, 'existe' => true, 'total' => count($names), 'nombres' => $names];
    }
}
