<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Events;

/**
 * Hay una entrega esperando en el outbox.
 *
 * Lo dispara `OutboxWebhookPublisher` por cada fila que escribe, y lo escucha
 * `DispatchWebhookDelivery`, que es un listener en cola: eso convierte el
 * evento en el punto donde la publicación deja de ser síncrona.
 *
 * **Viaja el id, no el modelo.** Un listener en cola serializa lo que recibe, y
 * un modelo serializado se rehidrata con el estado del momento en que se
 * encoló; entre medias el barrido del scheduler puede haber intentado la misma
 * entrega. Con el id, quien la entrega la lee cuando le toca.
 *
 * Es también la frontera pública del módulo (R5): otro módulo puede escucharlo
 * para llevar su propia contabilidad sin importar nada más de aquí.
 */
final readonly class WebhookDeliveryQueued
{
    public function __construct(
        public int $deliveryId,
        public string $event,
    ) {}
}
