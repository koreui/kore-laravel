<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Support\EndpointUrl;

/**
 * Cambia el nombre, la URL, los eventos o el interruptor de un endpoint.
 *
 * **No toca el secreto**, y es a propósito: cambiar la URL no invalida la clave
 * compartida, así que el suscriptor no tiene que reconfigurar nada por mover su
 * receptor de sitio. Rotar es una operación aparte y explícita
 * ({@see WebhookEndpointRotateSecretAction}).
 *
 * **La URL nueva pasa por {@see EndpointUrl}**, igual que en el alta y por lo
 * mismo: la edición es justo el sitio donde una integración legítima se
 * reapunta a `127.0.0.1`, y esta Action también sirve desde un comando, donde
 * no hay formulario que valide nada.
 */
final class WebhookEndpointUpdateAction extends Action
{
    public function handle(WebhookEndpoint $endpoint, WebhookEndpointData $data): WebhookEndpoint
    {
        EndpointUrl::guard($data->url);

        $endpoint->update([
            'name' => $data->name,
            'url' => $data->url,
            'subscribed_events' => array_values($data->events),
            'active' => $data->active,
        ]);

        return $endpoint->refresh();
    }
}
