<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Listeners;

use App\Core\Contracts\WebhookPublisher;
use App\Modules\Auth\Events\ApiTokenIssued;

/**
 * Auth emite un token, los suscriptores se enteran.
 *
 * Es toda la relación entre los dos módulos (R5): `App\Modules\Auth\Events\*` es
 * la única parte de Auth que este módulo importa, y Auth no sabe que Webhooks
 * existe. Apagar `WEBHOOKS_ENABLED` deja de registrar este listener y el login
 * por API sigue funcionando exactamente igual.
 *
 * Existe sobre todo como **ejemplo ejecutable**: es el evento del boilerplate
 * que demuestra el camino entero —evento de dominio → `publish()` → fila del
 * outbox → entrega firmada— sin que haya que inventarse un módulo de pedidos
 * para verlo. Un módulo propio hace exactamente esto desde su Action.
 *
 * **El payload no lleva secretos.** Va el id y el correo del usuario, el nombre
 * del token y de qué cliente venía; **nunca** el token en claro ni su hash. Lo
 * que se pasa a `publish()` acaba en el servidor de un tercero, y un token de
 * API en un log ajeno es una credencial regalada.
 *
 * No va en cola: `publish()` sólo escribe filas, y la entrega —que es lo que
 * tarda— ya es asíncrona por su cuenta.
 */
final readonly class PublishApiTokenIssued
{
    public const string EVENT = 'auth.api_token.issued';

    public function __construct(private WebhookPublisher $publisher) {}

    public function handle(ApiTokenIssued $event): void
    {
        $this->publisher->publish(self::EVENT, [
            'user' => [
                'id' => $event->user->id,
                'email' => $event->user->email,
            ],
            'token' => [
                'name' => $event->tokenName,
            ],
            'client' => [
                'platform' => $event->platform,
                'app_version' => $event->appVersion,
            ],
        ]);
    }
}
