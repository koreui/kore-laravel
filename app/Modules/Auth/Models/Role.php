<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Core\Enums\SystemRole;
use App\Modules\Auth\Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role custom — extiende Spatie y agrega constantes + helpers para selects.
 *
 * @property string $name
 * @property string $guard_name
 *
 * Los valores viven en {@see SystemRole} (`app/Core/Enums/`), que es lo que
 * pueden mirar los demás módulos sin importar `App\Modules\Auth\*` (regla 3
 * de CLAUDE.md). Las constantes se mantienen como alias porque son la API
 * histórica del boilerplate y las usan seeders, tests y proyectos derivados.
 *
 * Convención del boilerplate:
 * - SUPERADMIN: bypass total via Gate::before; sólo se asigna por consola
 *   y los usuarios con este rol están ocultos del listado UI.
 * - ADMIN: rol completo para el primer admin; tiene todos los permisos.
 * - USER: rol mínimo asignable a usuarios estándar.
 *
 * Para agregar más roles: añade el case a {@see SystemRole} y crea la lógica
 * de syncPermissions correspondiente en ModulesSeeder.
 */
final class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use LogsActivity;

    public const string SUPERADMIN = SystemRole::Superadmin->value;

    public const string ADMIN = SystemRole::Admin->value;

    public const string USER = SystemRole::User->value;

    /**
     * Roles seleccionables desde la UI. SUPERADMIN se excluye a propósito.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function allRoles(): array
    {
        return array_map(
            fn (SystemRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            SystemRole::assignable(),
        );
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
