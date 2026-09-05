<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * En qué punto está una entrega del outbox.
 *
 * Los cuatro estados se leen como una línea temporal, y la diferencia entre los
 * dos primeros es la que cuenta al mirar la pantalla:
 *
 *   - `pending`   · nunca se ha intentado, o se reencoló a mano. Espera a que
 *                   `next_attempt_at` venza.
 *   - `failed`    · se intentó y falló, y hay reintento programado. Es un
 *                   estado **transitorio**: el barrido lo vuelve a coger. Existe
 *                   aparte de `pending` porque «aún no se ha intentado» y «lleva
 *                   tres intentos fallando» son cosas distintas para quien mira
 *                   la tabla, y sin distinguirlas la pantalla no dice nada.
 *   - `delivered` · el receptor contestó 2xx. Final.
 *   - `exhausted` · se agotaron los intentos de `kore-webhooks.max_attempts`.
 *                   Final, salvo reintento manual.
 *
 * `pending` y `failed` son los dos que `WebhookDelivery::retryable()` recoge.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Delivered = 'delivered';
    case Exhausted = 'exhausted';

    /**
     * Los estados que siguen en juego: los que el barrido vuelve a intentar.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Failed];
    }

    /**
     * Los estados cerrados: los que `webhooks:prune` puede llevarse.
     *
     * @return array<int, self>
     */
    public static function closed(): array
    {
        return [self::Delivered, self::Exhausted];
    }

    /**
     * Etiqueta para la interfaz (R33: español es el idioma fuente).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pendiente'),
            self::Failed => __('Reintentando'),
            self::Delivered => __('Entregado'),
            self::Exhausted => __('Agotado'),
        };
    }

    /**
     * Color del badge de koreUi.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Failed => 'warning',
            self::Delivered => 'success',
            self::Exhausted => 'destructive',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
