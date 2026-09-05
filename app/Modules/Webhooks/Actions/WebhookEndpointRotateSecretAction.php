<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Support\WebhookSecret;

/**
 * Cambia el secreto de un endpoint y devuelve el nuevo.
 *
 * **Corta en seco**: desde la siguiente entrega, las firmas salen con la clave
 * nueva y el receptor que siga con la vieja las rechazará. No hay periodo de
 * gracia con dos claves válidas, y no lo hay porque una rotación se hace
 * justamente cuando la clave anterior se considera comprometida — dejarla
 * viviendo una hora más sería no haber rotado.
 *
 * De ahí que la pantalla enseñe el secreto nuevo una sola vez y avise: hay que
 * pegarlo en el otro lado antes de que salga el siguiente evento.
 */
final class WebhookEndpointRotateSecretAction extends Action
{
    public function handle(WebhookEndpoint $endpoint): string
    {
        $secret = WebhookSecret::generate();

        $endpoint->update(['secret' => $secret]);

        return $secret;
    }
}
