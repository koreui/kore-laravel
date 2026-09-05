<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Listeners;

use App\Modules\Webhooks\Actions\WebhookDeliverAction;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Tries;

/**
 * Saca del outbox lo que se acaba de encolar y lo entrega.
 *
 * Es un **listener en cola** y no un job porque la lista de carpetas de un
 * módulo no tiene `Jobs/` (R3): el trabajo en cola del boilerplate se escribe
 * como listener sobre un evento del módulo que delega en una Action.
 *
 * Dos detalles que hacen que el outbox funcione:
 *
 * - **`$afterCommit = true`.** El evento se dispara dentro de la transacción de
 *   quien publica; sin esto, un worker rápido podría leer la fila antes de que
 *   exista para él —o peor, entregar un webhook de una transacción que después
 *   se revierte—.
 * - **`#[Tries(1)]`.** Los reintentos los lleva la propia entrega, con su
 *   `attempts` y su backoff en la base. Si además reintentara la cola, habría
 *   dos relojes distintos sobre la misma fila y el receptor recibiría ráfagas.
 *   Por eso `WebhookDeliverAction` no lanza nunca: anota el fallo y programa la
 *   siguiente cita, que recogerá `webhooks:dispatch`.
 *
 * Y por eso llega el **id** y no el modelo: entre encolar y ejecutar, el barrido
 * del scheduler puede haber tocado la fila, así que se lee cuando toca.
 */
/** Reintentar es cosa de la entrega, no de la cola (ver el docblock). */
#[Tries(1)]
final class DispatchWebhookDelivery implements ShouldQueue
{
    /** Que el job no exista hasta que la transacción del dominio confirme. */
    public bool $afterCommit = true;

    public function __construct(private readonly WebhookDeliverAction $action) {}

    public function handle(WebhookDeliveryQueued $event): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($event->deliveryId);

        // Pudo borrarla `webhooks:prune`, o el borrado en cascada del endpoint.
        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        $this->action->handle($delivery);
    }
}
