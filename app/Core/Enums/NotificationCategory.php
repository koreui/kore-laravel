<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Las categorías de notificación del **núcleo**.
 *
 * Una categoría es el área por la que alguien elige recibir —o dejar de
 * recibir— avisos. Se pregunta por categoría y no por tipo de notificación
 * porque nadie quiere una pantalla con treinta interruptores, y porque añadir
 * un aviso nuevo no puede obligar a cada usuario a volver a configurar nada.
 *
 * **Este enum no es el catálogo completo, y no puede serlo:** un enum de PHP no
 * se extiende. Por eso `App\Core\Data\NotificationData::$category` es un
 * `string` y no este tipo — un proyecto derivado añade sus propias categorías
 * (`billing`, `expedientes`) en `config/kore-notifications.php` sin tocar una
 * línea de Core. Lo que este enum garantiza es que las tres del boilerplate se
 * pueden citar desde el código con una constante y no con un literal suelto:
 *
 * ```php
 * $notifier->notify($user->id, new NotificationData(
 *     title: __('Nuevo inicio de sesión por API'),
 *     body: __('Se emitió un token para «:name».', ['name' => $tokenName]),
 *     category: NotificationCategory::Account->value,
 * ));
 * ```
 *
 * Que las claves de aquí y las de `kore-notifications.categories` no se separen
 * lo verifica `NotificationsConfigTest`.
 *
 * Ver `docs/modules/notifications.md`.
 */
enum NotificationCategory: string
{
    /** Avisos de la plataforma: mantenimiento, versiones, cortes. */
    case System = 'system';

    /** La cuenta de quien recibe: sesiones nuevas, seguridad, datos. */
    case Account = 'account';

    /** Lo que pasa en su trabajo del día: algo que se le asignó o cambió. */
    case Activity = 'activity';

    /**
     * Etiqueta en español (R33). La traducción vive en el `en.json` del módulo
     * Notifications, que es quien la pinta.
     */
    public function label(): string
    {
        return match ($this) {
            self::System => __('Sistema'),
            self::Account => __('Cuenta'),
            self::Activity => __('Actividad'),
        };
    }
}
