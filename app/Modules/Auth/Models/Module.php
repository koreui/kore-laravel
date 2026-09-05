<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Database\Factories\ModuleFactory;
use App\Modules\Auth\Models\Collections\ModulesCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;

/**
 * Módulo del sistema.
 *
 * Cada Module representa un área funcional del producto (users, dashboard,
 * roles, etc.) y auto-genera sus permisos en formato {slug}.{action}. Las
 * acciones por default son view/create/edit/delete; los módulos con permisos
 * especiales (no CRUD) se declaran en {@see specialPermissions()}.
 *
 * El campo `roles` es metadata SÓLO para el UI (pre-selección de permisos al
 * elegir un rol en el formulario de usuario). NO afecta la lógica de
 * autorización de Spatie.
 *
 * @property string $name
 * @property string $slug
 * @property array<int, string>|null $roles
 * @property bool $active
 * @property-read array<int, array{value: string, label: string}> $permissions
 */
#[CollectedBy(ModulesCollection::class)]
#[Fillable(['name', 'slug', 'roles', 'active'])]
final class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'active' => 'bool',
        ];
    }

    /**
     * Permisos del módulo en formato {slug}.{action}.
     *
     * @return Attribute<array<int, array{value: string, label: string}>, never>
     */
    protected function permissions(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $special = $this->specialPermissions();

                return $special[$this->slug] ?? collect(['view', 'create', 'edit', 'delete'])
                    ->map(fn (string $action): array => [
                        'value' => $this->slug.'.'.$action,
                        'label' => ucfirst($action).' '.$this->name,
                    ])
                    ->all();
            },
        );
    }

    /**
     * Módulos con permisos especiales (no CRUD estándar).
     *
     * Para agregar un módulo con permisos no-CRUD, añadelo aquí en el formato:
     *   '{slug}' => [['value' => '{slug}.{accion}', 'label' => '...'], ...]
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function specialPermissions(): array
    {
        return [
            'dashboard' => [
                ['value' => 'dashboard.view', 'label' => 'Ver Dashboard'],
            ],
            /*
             * Ajustes de la instalación (módulo Platform). Un solo permiso y no
             * el CRUD de cuatro: no hay nada que crear ni que borrar —una clave
             * sin fila ya existe, vale su defecto—, así que `settings.create` y
             * `settings.delete` serían dos permisos que nadie podría comprobar
             * contra nada. Y ver los ajustes es administrarlos: incluyen datos
             * fiscales y de contacto, así que «mirar sin tocar» no es un nivel
             * de acceso que aporte nada.
             */
            'settings' => [
                ['value' => 'settings.manage', 'label' => 'Administrar Ajustes'],
            ],
        ];
    }
}
