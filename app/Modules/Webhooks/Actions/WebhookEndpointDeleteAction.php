<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Models\WebhookEndpoint;

/**
 * Borra un endpoint y, con él, sus entregas.
 *
 * El borrado en cascada lo hace la FK de `webhook_deliveries` (`cascadeOnDelete`
 * en la migración), no un bucle aquí: una integración con cien mil entregas se
 * borra en una sentencia y no en cien mil.
 *
 * Y sí se borra de verdad, no se archiva: un endpoint apagado ya existe
 * (`active = false`) y es lo que hace quien quiere conservar el histórico. Si
 * alguien pide el borrado es porque quiere que desaparezca.
 */
final class WebhookEndpointDeleteAction extends Action
{
    public function handle(WebhookEndpoint $endpoint): void
    {
        $endpoint->delete();
    }
}
