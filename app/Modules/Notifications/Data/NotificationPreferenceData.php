<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Core\Data\Data;

/**
 * La preferencia **efectiva** de una persona para una categoría: la fila
 * guardada, o el default del config cuando nunca tocó nada.
 *
 * Es un DTO y no el modelo porque quien la consume son una pantalla y un
 * `via()`: la Blade pinta interruptores, no filas de Eloquent (R30), y el
 * canal sólo pregunta tres booleanos. Al no ser un modelo tampoco hay forma de
 * confundir «esto viene de la base» con «esto es el default», que es
 * exactamente el bug que produce un `new NotificationPreference([...])` sin
 * guardar paseándose por la aplicación.
 */
final class NotificationPreferenceData extends Data
{
    public function __construct(
        public readonly string $category,
        public readonly bool $inApp = true,
        public readonly bool $mail = true,
        public readonly bool $push = false,
    ) {}
}
