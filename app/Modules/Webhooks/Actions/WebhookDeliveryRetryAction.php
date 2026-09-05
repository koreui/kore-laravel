<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * Reencola a mano una entrega, venga de donde venga.
 *
 * Es el botón de la pantalla del endpoint, y sirve sobre todo para el caso
 * `exhausted`: el receptor estuvo caído toda la noche, alguien lo arregla por la
 * mañana y quiere que salga lo que se quedó atrás sin esperar a nada.
 *
 * **Devuelve el contador de intentos a cero.** No es cosmética: si se dejara
 * como estaba, la entrega tendría un único intento antes de volver a agotarse,
 * y el arreglo del receptor no habría servido de nada. Lo que se conserva es
 * `last_error`, para que la pantalla siga contando qué pasó la última vez.
 *
 * Una entrega ya `delivered` no se reencola: mandarla otra vez sería un
 * duplicado pedido a mano, y el receptor no tiene por qué distinguirlo de un
 * reintento legítimo.
 */
final class WebhookDeliveryRetryAction extends Action
{
    public function handle(WebhookDelivery $delivery): bool
    {
        if ($delivery->status === DeliveryStatus::Delivered) {
            return false;
        }

        $delivery->update([
            'attempts' => 0,
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => CarbonImmutable::now(),
            'response_status' => null,
        ]);

        Event::dispatch(new WebhookDeliveryQueued($delivery->id, $delivery->event));

        return true;
    }
}
