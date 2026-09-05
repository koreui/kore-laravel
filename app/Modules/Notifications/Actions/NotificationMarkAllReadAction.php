<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Marca como leídas todas las no leídas de una persona.
 *
 * Un `update()` masivo y no un bucle de `markAsRead()`: es una consulta contra
 * las que sean, y aquí no hay eventos de modelo que perderse —una notificación
 * leída no dispara nada—.
 *
 * La hora se calcula una vez y se aplica a todas: si cada fila usara `now()`
 * por su cuenta, un lote grande acabaría con marcas de tiempo que sugieren una
 * lectura escalonada que nunca ocurrió.
 */
final class NotificationMarkAllReadAction extends Action
{
    /** @return int cuántas se marcaron */
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => CarbonImmutable::now()]);
    }
}
