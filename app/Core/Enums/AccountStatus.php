<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Estado de alta de una cuenta: si su dueño ya puede usar la aplicación.
 *
 * Vive en `Core` y no en el módulo Auth por la misma razón que
 * {@see SystemRole}: quien lo castea es `App\Models\User`, que es un modelo
 * **global** y no importa una sola clase de `App\Modules\*` (R5). Auth —dueño
 * del flujo de invitaciones y del middleware `account.active`— lo consume desde
 * aquí, igual que Users para su panel de estado.
 *
 * Es ortogonal a la verificación del email: `email_verified_at` responde «¿este
 * correo es suyo?» y esto responde «¿la casa le abrió la puerta?». Una cuenta
 * puede tener el email verificado y seguir en `Pending` porque nadie la ha
 * activado todavía.
 *
 * Con `AUTH_INVITATIONS=false` toda cuenta nace `Active` y el estado nunca
 * cambia: la columna existe (un toggle no apaga el esquema) pero no gobierna
 * nada. Ver `docs/modules/auth.md`.
 */
enum AccountStatus: string
{
    /** Se registró por un flujo que no la activa; falta que alguien la apruebe. */
    case Pending = 'pending';

    /** Puede operar con normalidad. Es el estado por defecto de la columna. */
    case Active = 'active';

    /** Se le cerró el acceso a mano. Ni siquiera puede mantener la sesión. */
    case Suspended = 'suspended';

    /**
     * Etiqueta legible para badges y tablas.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pendiente de activación'),
            self::Active => __('Activa'),
            self::Suspended => __('Suspendida'),
        };
    }

    /**
     * Color de `<x-kore::badge :color>`. Los tres valores existen en la paleta
     * de koreUi; ver `vendor/kore-ui/kore-ui/resources/views/components/badge.blade.php`.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Suspended => 'destructive',
        };
    }

    /**
     * ¿Puede usar la aplicación más allá de su propia sesión?
     *
     * Es la única pregunta que hace `EnsureAccountIsActive`, y por eso vive en
     * el enum: si mañana aparece un cuarto estado, la respuesta se escribe aquí
     * y el middleware no se entera.
     */
    public function canOperate(): bool
    {
        return $this === self::Active;
    }
}
