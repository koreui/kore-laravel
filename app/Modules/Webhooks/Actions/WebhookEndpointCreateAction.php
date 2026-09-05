<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Support\WebhookSecret;

/**
 * Da de alta un suscriptor y le genera su secreto.
 *
 * El secreto **no llega del formulario**: lo genera {@see WebhookSecret}.
 * Dejarlo elegir sería aceptar la entropía que al suscriptor le apeteciera, y
 * esto no es una contraseña que nadie tenga que recordar: se copia una vez y se
 * pega en el otro lado.
 *
 * `$actorId` llega por parámetro porque una Action tiene que servir igual desde
 * un comando o un seeder, donde `auth()` devuelve null en silencio (R19).
 */
final class WebhookEndpointCreateAction extends Action
{
    public function handle(WebhookEndpointData $data, ?int $actorId = null): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create([
            'name' => $data->name,
            'url' => $data->url,
            'secret' => WebhookSecret::generate(),
            'subscribed_events' => array_values($data->events),
            'active' => $data->active,
            'created_by' => $actorId,
        ]);
    }
}
