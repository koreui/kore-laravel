<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Core\Actions\Action;
use App\Core\Enums\AccountStatus;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Auth\Events\AccountActivated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * Cambia el estado de alta de una cuenta desde el panel de Users.
 *
 * El actor llega por parámetro y no de `auth()` (R19), y aquí no es decoración:
 * es lo que hace posible la guarda que importa. **Nadie cambia su propio
 * estado**, y esa regla no puede vivir en la Policy porque el `Gate::before`
 * del superadmin la saltaría —exactamente la misma cicatriz que el auto-borrado
 * de `TableUsers::deleteAuthorized()`—. Sin ella, un superadmin puede
 * suspenderse a sí mismo y quedarse fuera de la única pantalla desde la que
 * podría revertirlo.
 *
 * `activated_at` se escribe la **primera** vez que la cuenta se activa y no se
 * toca después: reactivar a alguien tras una suspensión no borra la fecha en la
 * que entró. Y `AccountActivated` sólo se dispara cuando el estado **cambia** a
 * activo: volver a pulsar «Activar» sobre una cuenta activa no vuelve a mandar
 * el correo de bienvenida.
 */
final class UserAccountStatusChangeAction extends Action
{
    public function handle(User $user, AccountStatus $status, User $actor): User
    {
        if ($user->id === $actor->id) {
            throw new ConflictException(__('No puedes cambiar el estado de tu propia cuenta.'));
        }

        $previous = $user->accountStatus();

        if ($previous === $status) {
            return $user;
        }

        $attributes = ['account_status' => $status];

        if ($status === AccountStatus::Active && ($user->getAttributes()['activated_at'] ?? null) === null) {
            $attributes['activated_at'] = CarbonImmutable::now();
        }

        $user->forceFill($attributes)->save();

        if ($status === AccountStatus::Active) {
            Event::dispatch(new AccountActivated($user, $actor->id));
        }

        return $user;
    }
}
