<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use InvalidArgumentException;

/**
 * Publicar un evento del dominio hacia fuera de la aplicación.
 *
 * Es la frontera entre el módulo que **implementa** la salida
 * (`App\Modules\Webhooks`, con su outbox y sus reintentos) y todo el que sólo
 * la **usa**: una Action de facturación que acaba de emitir una factura, un
 * listener que reacciona a un alta. Ninguno de ellos importa una sola clase de
 * Webhooks (R5), ni sabe qué endpoints hay suscritos, ni si la entrega salió a
 * la primera.
 *
 * La implementación se bindea en `WebhooksModuleServiceProvider::register()` y
 * **sólo con `WEBHOOKS_ENABLED=true`**. Con el toggle apagado no hay binding:
 * quien resuelva el contrato recibe un `BindingResolutionException` —«esta
 * instalación no manda webhooks»— en vez de un silencio que parece un envío.
 * Por eso quien lo consume pregunta antes por
 * `config('kore-app.webhooks.enabled')`:
 *
 * ```php
 * if ((bool) config('kore-app.webhooks.enabled')) {
 *     resolve(WebhookPublisher::class)->publish('orders.created', $order->toArray());
 * }
 * ```
 *
 * ## Es un outbox, y por eso no manda nada
 *
 * `publish()` **no hace ninguna petición HTTP**: escribe una fila por endpoint
 * suscrito, en la misma transacción de quien publica, y deja que la entrega la
 * haga después un listener en cola. Si la transacción del dominio se revierte,
 * las filas se van con ella y no sale ningún webhook contando un pedido que no
 * existe. Ver `docs/modules/webhooks.md`.
 */
interface WebhookPublisher
{
    /**
     * Encola el evento para todos los endpoints activos que lo escuchan.
     *
     * @param array<string, mixed> $payload datos del evento, ya serializables a
     *                                      JSON y **sin secretos**: lo que se
     *                                      pase aquí acaba en un servidor de
     *                                      terceros
     *
     * @throws InvalidArgumentException si `$event` no está en el catálogo
     *                                  `kore-webhooks.events`. Un nombre mal
     *                                  escrito no puede fallar en silencio: el
     *                                  suscriptor no recibiría nada y nadie se
     *                                  enteraría hasta que lo reclamara.
     */
    public function publish(string $event, array $payload): void;
}
