<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Enums\NotificationCategory;

/**
 * La forma única de cualquier aviso del boilerplate.
 *
 * Sin este DTO cada módulo inventaría su propio `data`, y la bandeja —web y
 * móvil— tendría que conocer cada tipo para saber qué pintar. Con él, una
 * notificación nueva aparece bien en las dos superficies sin tocar ninguna
 * pantalla.
 *
 * Vive en `App\Core\Data` y no en el módulo a propósito: es el argumento de
 * `App\Core\Contracts\Notifier`, así que quien notifica no importa nada de
 * `App\Modules\Notifications` (R5).
 *
 * Tres decisiones que no son detalles:
 *
 * - **`category` es un `string`, no el enum.** `App\Core\Enums\NotificationCategory`
 *   lista las tres del núcleo, pero un enum no se extiende: si el tipo fuera el
 *   enum, un derivado no podría tener una categoría propia sin editar Core. El
 *   catálogo real vive en `config/kore-notifications.php`.
 * - **`mail` y `push` son un techo, no una orden.** Dicen «este aviso *puede*
 *   salir por correo o por push»; quien decide si sale de verdad son las
 *   preferencias de la persona. Un aviso de un solo canal se manda con
 *   `mail: false` y no hay forma de saltarse la preferencia contraria.
 * - **`data` es para quien lo necesite** (ids, nombres). La bandeja NO depende
 *   de su contenido: pinta título, cuerpo y, si hay, el enlace de `url`.
 */
final class NotificationData extends Data
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $category = NotificationCategory::System->value,
        public readonly ?string $url = null,
        public readonly array $data = [],
        public readonly bool $mail = true,
        public readonly bool $push = true,
    ) {}
}
