<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Core\Contracts\Notifier;
use App\Core\Data\NotificationData;
use App\Modules\Notifications\Actions\NotificationSendAction;

/**
 * La implementación de `App\Core\Contracts\Notifier` del boilerplate.
 *
 * Es deliberadamente delgada: el caso de uso vive en
 * {@see NotificationSendAction} (R4), y esto sólo lo publica bajo el nombre que
 * el resto de la aplicación conoce. Separarlos no es ceremonia — es lo que
 * permite que un derivado sustituya el contrato entero (un servicio externo, un
 * bus de eventos) sin perder la Action, y que la Action se pueda llamar desde
 * un comando sin pasar por el contrato.
 *
 * Se bindea en `NotificationsModuleServiceProvider::register()` **sólo con el
 * toggle encendido**; con él apagado no hay binding y resolver `Notifier` lanza
 * un `BindingResolutionException`, que es la respuesta correcta y no un aviso
 * que desaparece en silencio. Mismo criterio que `FileStore`.
 *
 * El nombre dice qué canal manda: la bandeja de base de datos es el canal base,
 * y el correo y el push cuelgan de ella. Un derivado que sólo quiera correo
 * bindea su propia clase y este archivo se queda como referencia.
 */
final readonly class DatabaseNotifier implements Notifier
{
    public function __construct(private NotificationSendAction $send) {}

    public function notify(int $userId, NotificationData $payload): void
    {
        $this->send->handle([$userId], $payload);
    }

    /**
     * @param array<int, int> $userIds
     */
    public function notifyMany(array $userIds, NotificationData $payload): void
    {
        $this->send->handle($userIds, $payload);
    }
}
