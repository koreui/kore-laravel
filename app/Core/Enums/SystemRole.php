<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Roles del sistema, compartidos por todos los módulos.
 *
 * Vive en `Core` (y no en el módulo Auth) porque cualquier módulo necesita
 * poder comparar contra un rol sin importar `App\Modules\Auth\*` — regla 3 de
 * CLAUDE.md. `App\Modules\Auth\Models\Role` define sus constantes a partir de
 * este enum, así que el valor sigue siendo único.
 *
 * - `Superadmin`: bypass total vía `Gate::before`. Sólo se asigna por consola
 *   y los usuarios con este rol están ocultos del listado UI.
 * - `Admin`: rol completo; tiene todos los permisos.
 * - `User`: rol mínimo asignable a usuarios estándar.
 */
enum SystemRole: string
{
    case Superadmin = 'superadmin';

    case Admin = 'Administrador';

    case User = 'Usuario';

    /**
     * Etiqueta legible para selects y badges.
     */
    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::Admin => 'Administrador',
            self::User => 'Usuario',
        };
    }

    /**
     * Roles ofrecidos por la UI. `Superadmin` se excluye a propósito.
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $role !== self::Superadmin,
        ));
    }
}
