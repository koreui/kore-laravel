<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Quién puede ver y tocar una notificación: su destinatario, y nadie más.
 *
 * **Este módulo no tiene permisos propios** (`notifications.view` no existe), y
 * es a propósito: una bandeja no es una sección a la que se dé acceso, es algo
 * que todo el mundo tiene. Lo que hay que decidir no es «¿puede entrar?» sino
 * «¿es suya?», y eso es exactamente lo que una Policy sabe hacer (R25).
 *
 * La comprobación mira el par completo (`notifiable_type`, `notifiable_id`) y
 * no sólo el id: la tabla es polimórfica, así que sin el tipo un modelo
 * cualquiera con el mismo id entraría por la puerta de al lado.
 *
 * Se registra en el provider con `Gate::policy(DatabaseNotification::class, ...)`.
 * El modelo es del framework, no del módulo, y eso está bien: la tabla estándar
 * es lo que hace que `markAsRead()` funcione sin código propio.
 */
final class NotificationPolicy
{
    /**
     * Todo el mundo tiene bandeja.
     *
     * No es un `return true` de relleno: es la decisión que hace falta para que
     * «marcar todas como leídas» —que no tiene un modelo al que apuntar— pase
     * igualmente por la Policy y no por un método sin puerta. Qué filas toca
     * esa operación lo decide la relación del usuario, no esto.
     */
    public function viewAny(): bool
    {
        return true;
    }

    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $this->belongsTo($user, $notification);
    }

    /** Marcar como leída. */
    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $this->belongsTo($user, $notification);
    }

    public function delete(User $user, DatabaseNotification $notification): bool
    {
        return $this->belongsTo($user, $notification);
    }

    private function belongsTo(User $user, DatabaseNotification $notification): bool
    {
        return (string) $notification->getAttribute('notifiable_type') === $user->getMorphClass()
            && (string) $notification->getAttribute('notifiable_id') === (string) $user->getKey();
    }
}
