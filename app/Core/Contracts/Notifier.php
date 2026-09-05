<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Data\NotificationData;

/**
 * Avisar a alguien.
 *
 * Es la frontera entre el módulo que **implementa** las notificaciones
 * (`App\Modules\Notifications`) y todo el que sólo las **usa**: un listener que
 * reacciona a un evento de otro módulo, una Action que termina un proceso
 * largo, un comando de mantenimiento. Ninguno importa una clase del módulo
 * (R5), ni sabe si el aviso acabó en la bandeja, en un correo o en un push.
 *
 * La implementación se bindea en `NotificationsModuleServiceProvider::register()`
 * y **sólo con `NOTIFICATIONS_ENABLED=true`**. Con el toggle apagado no hay
 * binding: quien resuelva el contrato recibe un `BindingResolutionException`,
 * que es la respuesta correcta —«esta instalación no notifica»— y no un aviso
 * que desaparece en silencio. Es el mismo criterio que `FileStore` y
 * `PdfRenderer`, y por eso quien lo consume pregunta antes por
 * `config('kore-app.notifications.enabled')`.
 *
 * **El destinatario es un `int`, no un `User`.** El contrato vive en Core, donde
 * `auth()` está prohibido (R19) y donde no se quiere arrastrar Eloquent: un id
 * es lo único que un job serializado, un comando o un listener tienen siempre a
 * mano. Quien resuelve el modelo es la implementación.
 *
 * Ver `docs/modules/notifications.md`.
 */
interface Notifier
{
    /**
     * Avisa a una persona.
     *
     * No lanza si el usuario no existe: un aviso es un efecto secundario de
     * algo que ya pasó, y hacer fallar la operación original porque el
     * destinatario se borró entre medias sería cambiar el resultado por el
     * acuse de recibo.
     */
    public function notify(int $userId, NotificationData $payload): void;

    /**
     * Avisa a varias personas del mismo hecho.
     *
     * Los ids repetidos cuentan una sola vez: la misma persona puede llegar por
     * dos caminos (es la autora y además la responsable) y recibir dos veces el
     * mismo aviso es un fallo, no una redundancia útil.
     *
     * @param array<int, int> $userIds
     */
    public function notifyMany(array $userIds, NotificationData $payload): void;
}
