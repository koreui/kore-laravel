<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Marca una notificación como leída.
 *
 * El usuario llega **por parámetro y la consulta arranca de su relación**, no
 * de `DatabaseNotification::find($id)`: el id de una notificación es un uuid
 * que viaja al cliente, y si la búsqueda fuera global bastaría con mandar el de
 * otra persona para marcársela. Al partir de `$user->notifications()` el ámbito
 * lo pone la propia consulta, no una comprobación que alguien puede olvidar.
 *
 * Idempotente: marcar dos veces no mueve la marca de tiempo original, que es la
 * que dice cuándo se leyó de verdad.
 */
final class NotificationMarkReadAction extends Action
{
    public function handle(User $user, string $notificationId): bool
    {
        $notification = $user->notifications()->whereKey($notificationId)->first();

        if (! $notification instanceof DatabaseNotification) {
            return false;
        }

        if ($notification->getAttribute('read_at') !== null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }
}
