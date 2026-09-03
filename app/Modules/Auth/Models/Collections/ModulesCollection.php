<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models\Collections;

use App\Modules\Auth\Models\Module;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Collection<int, Module>
 */
final class ModulesCollection extends Collection
{
    /**
     * Estructura completa para el editor de permisos en la UI.
     *
     * @return array<int, array{module: string, permissions: array<int, array{value: string, label: string}>, roles: array<int, string>|null}>
     */
    public function permissionsToArray(): array
    {
        return $this->map(fn (Module $module): array => [
            'module' => $module->name,
            'permissions' => $module->permissions,
            'roles' => $module->roles,
        ])->all();
    }

    /**
     * Lista plana de TODOS los permisos generados por los módulos.
     *
     * @return array<int, string>
     */
    public function flatPermissions(): array
    {
        return $this->map(fn (Module $module): array => collect($module->permissions)->pluck('value')->toArray())
            ->flatten()
            ->toArray();
    }
}
