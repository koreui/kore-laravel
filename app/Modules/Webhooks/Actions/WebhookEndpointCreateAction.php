<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Support\EndpointUrl;
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
 *
 * **Y por eso mismo la URL se vuelve a comprobar aquí** ({@see EndpointUrl}),
 * aunque el formulario ya lo hiciera: si la Action sirve desde un comando o un
 * seeder, ahí no hay validador y una URL apuntando a `169.254.169.254` entraría
 * sin que nadie dijera nada. Es la misma razón por la que
 * `Platform\Actions\SettingUpdateAction` repite la validación de sus claves.
 */
final class WebhookEndpointCreateAction extends Action
{
    public function handle(WebhookEndpointData $data, ?int $actorId = null): WebhookEndpoint
    {
        EndpointUrl::guard($data->url);

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
