<?php

declare(strict_types=1);

namespace App\Core\Data\Authorization;

use App\Core\Data\Data;
use Spatie\LaravelData\Attributes\DataCollectionOf;

/**
 * Un módulo del sistema con sus permisos, tal y como lo consume la matriz de
 * permisos del formulario de usuarios.
 *
 * `roles` es metadata SÓLO de UI: Alpine la usa para auto-seleccionar los
 * permisos al elegir un rol. No participa en ninguna decisión de autorización.
 */
final class PermissionModuleData extends Data
{
    /**
     * @param array<int, PermissionOptionData> $permissions
     * @param array<int, string>|null $roles
     */
    public function __construct(
        public readonly string $module,
        #[DataCollectionOf(PermissionOptionData::class)]
        public readonly array $permissions,
        public readonly ?array $roles,
    ) {}

    /**
     * Los valores (`{slug}.{accion}`) de los permisos del módulo.
     *
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return array_map(
            fn (PermissionOptionData $permission): string => $permission->value,
            $this->permissions,
        );
    }
}
