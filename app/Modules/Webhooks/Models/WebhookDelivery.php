<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Core\Concerns\HasPublicUuid;
use App\Modules\Webhooks\Database\Factories\WebhookDeliveryFactory;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Una fila del outbox: «este evento tiene que llegar a este endpoint».
 *
 * Nace dentro de la transacción de quien publica y con el cuerpo ya congelado
 * (`payload`), no con una referencia a la fila del dominio. Un reintento de
 * mañana manda lo que pasó hoy: si el pedido cambió de precio por el camino, el
 * suscriptor recibe la historia real y no un resumen retocado.
 *
 * `uuid` no es decorativo: viaja en la cabecera `X-Kore-Delivery`, y es lo que
 * permite al receptor descartar el duplicado que produce un reintento después
 * de un 2xx perdido en la red.
 *
 * @property int $id
 * @property string $uuid
 * @property int $endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property DeliveryStatus $status
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $last_error
 * @property int|null $response_status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'endpoint_id',
    'event',
    'payload',
    'attempts',
    'status',
    'next_attempt_at',
    'delivered_at',
    'last_error',
    'response_status',
])]
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasPublicUuid;

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * Las que siguen en juego y ya vencieron: exactamente lo que barre
     * `webhooks:dispatch`.
     *
     * El `whereNull` del `next_attempt_at` cubre a la que se creó sin fecha
     * —no debería pasar, pero una fila sin cita no puede quedarse esperando
     * para siempre—.
     *
     * `protected` porque Larastan lo exige para los scopes de Laravel 13; se
     * sigue llamando como `WebhookDelivery::query()->due($now)`.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function due(Builder $query, CarbonImmutable $now): void
    {
        $query
            ->whereIn('status', array_column(DeliveryStatus::open(), 'value'))
            ->where(function (Builder $inner) use ($now): void {
                $inner->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'int',
            'response_status' => 'int',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
