<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role custom — extiende Spatie y agrega constantes + helpers para selects.
 *
 * @property string $name
 * @property string $guard_name
 *
 * Convención del boilerplate:
 * - SUPERADMIN: bypass total via Gate::before; sólo se asigna por consola
 *   y los usuarios con este rol están ocultos del listado UI.
 * - ADMIN: rol completo para el primer admin; tiene todos los permisos.
 * - USER: rol mínimo asignable a usuarios estándar.
 *
 * Para agregar más roles: añade la constante, súmala a allRoles() y crea
 * la lógica de syncPermissions correspondiente en ModulesSeeder.
 */
class Role extends SpatieRole
{
    use LogsActivity;

    public const string SUPERADMIN = 'superadmin';

    public const string ADMIN = 'Administrador';

    public const string USER = 'Usuario';

    /**
     * Roles seleccionables desde la UI. SUPERADMIN se excluye a propósito.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function allRoles(): array
    {
        return [
            ['value' => self::ADMIN, 'label' => 'Administrador'],
            ['value' => self::USER, 'label' => 'Usuario'],
        ];
    }

    /** @return array<int, string> */
    public static function assignableNames(): array
    {
        return array_column(self::allRoles(), 'value');
    }

    /**
     * Audit log (spatie/laravel-activitylog). Se registra `guard_name` en vez
     * de `email` (que no existe en este modelo): cambiar el guard de un rol es
     * exactamente el tipo de movimiento que interesa auditar.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
