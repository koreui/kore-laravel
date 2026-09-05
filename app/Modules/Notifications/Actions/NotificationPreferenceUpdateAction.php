<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationPreferences;

/**
 * Guarda la preferencia de una persona para una categoría.
 *
 * `updateOrCreate` sobre el par (usuario, categoría), que es el índice único de
 * la tabla: la primera vez crea la fila y a partir de ahí la reescribe. Hasta
 * que existe, quien manda es el default del config — por eso guardar «lo mismo
 * que ya venía por defecto» no es una operación vacía: fija esa elección para
 * que un cambio futuro del default no se la pise.
 *
 * Vacía la caché de `NotificationPreferences` al terminar: si no, un aviso
 * mandado en la misma petición usaría la preferencia anterior.
 */
final class NotificationPreferenceUpdateAction extends Action
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function handle(User $user, NotificationPreferenceData $data): NotificationPreference
    {
        $preference = NotificationPreference::updateOrCreate(
            ['user_id' => $user->getKey(), 'category' => $data->category],
            [
                'in_app' => $data->inApp,
                'mail' => $data->mail,
                'push' => $data->push,
            ],
        );

        $this->preferences->forget();

        return $preference;
    }
}
