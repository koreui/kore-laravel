<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Core\Contracts\Notifier;
use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;
use App\Modules\Auth\Events\ApiTokenIssued;

/**
 * Auth emite un token, la persona se entera.
 *
 * Es el aviso de seguridad más barato que existe: alguien entró con tu cuenta
 * desde un cliente de API, y si no fuiste tú tienes dónde verlo. Va en la
 * categoría `account` porque es de la cuenta de quien lo recibe, no del trabajo
 * del día — y porque el default de esa categoría trae el correo encendido: un
 * inicio de sesión que sólo se ve entrando es un aviso que llega tarde.
 *
 * **Es también el ejemplo vivo de R5**, y por eso el módulo lo trae en vez de
 * dejar la carpeta `Listeners/` vacía: `App\Modules\Auth\Events\*` es la única
 * parte de Auth que Notifications importa, Auth no sabe que este módulo existe,
 * y apagar `NOTIFICATIONS_ENABLED` deja de registrarlo sin que un login por API
 * cambie en nada. Es la misma pieza que ya usa Devices sobre el mismo evento;
 * dos módulos escuchando el mismo hecho sin conocerse entre ellos.
 *
 * `Notifier` se inyecta por constructor, así que el listener sólo existe cuando
 * el contrato está bindeado — que es exactamente cuando el provider lo
 * registra.
 */
final readonly class NotifyOnApiTokenIssued
{
    public function __construct(private Notifier $notifier) {}

    public function handle(ApiTokenIssued $event): void
    {
        $this->notifier->notify((int) $event->user->getKey(), new NotificationData(
            title: __('Nuevo inicio de sesión por API'),
            body: __('Se emitió un token para «:name».', ['name' => $event->tokenName]),
            category: NotificationCategory::Account->value,
            data: [
                'token_id' => $event->tokenId,
                'device_id' => $event->deviceId,
                'platform' => $event->platform,
                'app_version' => $event->appVersion,
            ],
            // Sin push: avisar al mismo teléfono que acaba de entrar es ruido.
            // El correo sí, porque el punto del aviso es que llegue a otro
            // sitio distinto de aquel desde el que se entró.
            push: false,
        ));
    }
}
