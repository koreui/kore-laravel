<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Models\WebhookEndpoint;

/**
 * Cambia el nombre, la URL, los eventos o el interruptor de un endpoint.
 *
 * **No toca el secreto**, y es a propósito: cambiar la URL no invalida la clave
 * compartida, así que el suscriptor no tiene que reconfigurar nada por mover su
 * receptor de sitio. Rotar es una operación aparte y explícita
 * ({@see WebhookEndpointRotateSecretAction}).
 */
final class WebhookEndpointUpdateAction extends Action
{
    public function handle(WebhookEndpoint $endpoint, WebhookEndpointData $data): WebhookEndpoint
    {
        $endpoint->update([
            'name' => $data->name,
            'url' => $data->url,
            'subscribed_events' => array_values($data->events),
            'active' => $data->active,
        ]);

        return $endpoint->refresh();
    }
}
