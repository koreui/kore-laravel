<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Core\Enums\AccountStatus;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Users\Actions\UserAccountStatusChangeAction;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Estado de alta de una persona, con la palanca de quien administra.
 *
 * Vive en la pantalla de edición de un usuario y sólo con `AUTH_INVITATIONS`
 * encendido: con el toggle apagado el estado no gobierna nada y un panel para
 * moverlo sería una palanca desconectada.
 *
 * Las dos guardas que no puede poner la Policy están en la Action
 * (`UserAccountStatusChangeAction`): nadie cambia su propio estado, porque el
 * `Gate::before` del superadmin dejaría pasar cualquier `authorize()`. La que sí
 * pone la Policy es la otra —sólo un superadmin toca a otro superadmin—, y por
 * eso aquí basta con `authorize('update', …)`.
 */
final class AccountStatusPanel extends Component
{
    use InteractsWithFeedback;

    /**
     * `#[Locked]` porque identifica sobre quién se opera: sin el candado sería
     * el navegador quien eligiera a qué cuenta se le cambia el estado (R24).
     */
    #[Locked]
    public User $user;

    public function activate(): void
    {
        $this->changeTo(AccountStatus::Active, __('La cuenta ya puede entrar.'));
    }

    public function suspend(): void
    {
        $this->changeTo(AccountStatus::Suspended, __('La cuenta quedó suspendida.'));
    }

    /** El estado actual, ya tipado, para que la vista no toque Eloquent (R30). */
    #[Computed]
    public function status(): AccountStatus
    {
        return $this->user->accountStatus();
    }

    /**
     * ¿Se pinta la palanca?
     *
     * No para uno mismo: la Action lo rechazaría igual, pero enseñar un botón
     * que sólo puede dar error es peor que no enseñarlo.
     */
    #[Computed]
    public function canChange(): bool
    {
        return $this->user->id !== auth()->id();
    }

    public function render(): mixed
    {
        return view('users::livewire.account-status-panel');
    }

    /**
     * Autoriza → Action → toast, para los dos botones.
     *
     * El `authorize()` va aquí y no en la ruta: la llamada viaja por
     * `/livewire/update`, donde el `permission:users.edit` de
     * `/users/{user}/edit` no corre (R23).
     *
     * El `ConflictException` de la Action —la guarda de «no puedes cambiarte a
     * ti mismo»— se convierte en un aviso y no en un 500: es una decisión de
     * negocio comunicada a una persona, no un fallo.
     */
    private function changeTo(AccountStatus $status, string $message): void
    {
        $this->authorize('update', $this->user);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            resolve(UserAccountStatusChangeAction::class)->handle($this->user, $status, $actor);
        } catch (ConflictException $e) {
            $this->toast()->error(__('No se pudo cambiar'), $e->getMessage())->send();

            return;
        }

        $this->user->refresh();
        unset($this->status);

        $this->toast()->success(__('¡Listo!'), $message)->send();
    }
}
