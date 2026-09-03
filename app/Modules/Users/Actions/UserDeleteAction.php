<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Users\Events\UserDeleted;

/**
 * Borra un usuario.
 *
 * Las guardas de negocio (no borrarse a uno mismo, no borrar a un superadmin)
 * viven en `UserPolicy` y en el componente que llama: esta Action asume que la
 * decisión ya está tomada, para poder usarse también desde consola.
 */
final class UserDeleteAction extends Action
{
    public function handle(User $user): void
    {
        $user->delete();

        event(new UserDeleted($user));
    }
}
