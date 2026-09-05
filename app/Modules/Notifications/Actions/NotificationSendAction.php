<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Core\Actions\Action;
use App\Core\Data\NotificationData;
use App\Models\User;
use App\Modules\Notifications\Support\GenericNotification;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Support\Facades\Notification;

/**
 * El punto único por el que sale cualquier aviso.
 *
 * Que todo pase por aquí permite dos cosas que después son caras de añadir:
 * quitar los repetidos —la misma persona puede llegar por dos caminos, y dos
 * avisos idénticos son un fallo— y tener un solo sitio donde meter mañana la
 * exclusión de quien provocó el evento o el paso a cola.
 *
 * Un id que ya no corresponde a nadie simplemente no aparece en el resultado:
 * un aviso es el efecto secundario de algo que ya ocurrió, y tumbar la
 * operación original porque el destinatario se borró entre medias sería cambiar
 * el resultado por el acuse de recibo.
 */
final class NotificationSendAction extends Action
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * @param array<int, int> $userIds
     * @return int a cuántas personas se les mandó (las que existen)
     */
    public function handle(array $userIds, NotificationData $payload): int
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $recipients = User::query()->whereKey($ids)->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::send($recipients, new GenericNotification($payload, $this->preferences));

        return $recipients->count();
    }
}
