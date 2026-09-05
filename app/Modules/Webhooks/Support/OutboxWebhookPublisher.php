<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Support;

use App\Core\Contracts\WebhookPublisher;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * La implementación del contrato: escribe en el outbox y no manda nada.
 *
 * ## Por qué es un outbox y no una llamada HTTP
 *
 * `publish()` corre **dentro de la transacción de quien publica**. Es la razón
 * de ser del patrón: una Action que crea un pedido y publica
 * `orders.created` hace las dos cosas en la misma transacción, así que
 *
 *   - si la transacción se revierte, las filas del outbox se van con ella y no
 *     sale ningún webhook contando un pedido que no existe;
 *   - si se confirma, las filas están escritas y la entrega ocurrirá — hoy por
 *     el listener en cola, o mañana por el barrido de `webhooks:dispatch` si la
 *     cola se cayó entera.
 *
 * Una llamada HTTP en ese punto no puede dar ninguna de las dos garantías: no
 * se puede «deshacer» una petición ya enviada, y una petición que falla dentro
 * de una transacción o tumba el pedido o se pierde en silencio. Además ataría
 * el tiempo de respuesta del usuario al servidor de un tercero (R22).
 *
 * El listener en cola va con `$afterCommit = true`, así que el trabajo no se
 * despacha hasta que la transacción confirma: sin eso, un worker rápido podría
 * leer la fila antes de que exista para él.
 *
 * ## Qué se escribe
 *
 * Una fila por endpoint **activo y suscrito**. Un endpoint apagado no acumula
 * cola: cuando se vuelva a encender no recibirá de golpe lo que se perdió, y es
 * lo que se quiere — un webhook viejo describe un mundo que ya cambió.
 */
final readonly class OutboxWebhookPublisher implements WebhookPublisher
{
    public function publish(string $event, array $payload): void
    {
        $this->guardAgainstUnknownEvent($event);

        $endpoints = WebhookEndpoint::query()
            ->active()
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event));

        $now = CarbonImmutable::now();

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => $payload,
                'attempts' => 0,
                'status' => DeliveryStatus::Pending,
                'next_attempt_at' => $now,
            ]);

            Event::dispatch(new WebhookDeliveryQueued($delivery->id, $event));
        }
    }

    /**
     * Un evento fuera del catálogo es un error de programación, no un dato
     * malo: el suscriptor no recibiría nada y nadie se enteraría hasta que lo
     * reclamara. Por eso lanza en vez de devolver.
     *
     * @throws InvalidArgumentException
     */
    private function guardAgainstUnknownEvent(string $event): void
    {
        /** @var array<string, string> $catalog */
        $catalog = (array) config('kore-webhooks.events', []);

        if (array_key_exists($event, $catalog)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'El evento «%s» no está en el catálogo de config/kore-webhooks.php. Añádelo con su descripción antes de publicarlo (eventos conocidos: %s).',
            $event,
            $catalog === [] ? 'ninguno' : implode(', ', array_keys($catalog)),
        ));
    }
}
