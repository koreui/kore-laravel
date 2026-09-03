<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire;

use App\Models\User;
use App\Modules\Auth\Actions\AuthPasskeyDeleteAction;
use App\Modules\Auth\Data\PasskeyData;
use Illuminate\Contracts\View\View;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Laravel\Passkeys\Passkey;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Pantalla `/user/passkeys`: lista, alta y revocación de credenciales WebAuthn.
 *
 * El **alta no pasa por aquí**. La ceremonia de registro la hace el navegador y
 * la cierra el propio endpoint de Fortify (`POST /user/passkeys`), que es quien
 * sabe validar la atestación; el componente sólo se entera porque Alpine llama a
 * `$wire.$refresh()` cuando Fortify responde 200. Meter esa validación en un
 * método Livewire sería reescribir el paquete.
 *
 * La **revocación** sí vive aquí, para poder dar feedback sin recargar. Y por
 * eso repite dentro las dos guardas que el middleware de la ruta no puede
 * aplicar a `/livewire/update` (R23): la propiedad de la credencial y la
 * vigencia de la confirmación de contraseña.
 */
final class Passkeys extends Component
{
    use InteractsWithFeedback;

    /**
     * Revoca una passkey del usuario autenticado.
     *
     * Tres barreras, en este orden:
     *
     * 1. **La confirmación de contraseña.** Fortify cuelga `password.confirm`
     *    de sus rutas de gestión y la ruta de esta pantalla lo repite, pero una
     *    llamada Livewire viaja por `/livewire/update`, donde ese middleware no
     *    corre: con la pantalla abierta, la ventana de confirmación puede haber
     *    caducado. Se comprueba otra vez aquí.
     * 2. **La propiedad, por consulta.** La credencial se busca dentro de
     *    `$user->passkeys()`, así que un id ajeno es un 404 y no una decisión
     *    del Gate — importante, porque el `Gate::before` del superadmin
     *    devolvería `true` para cualquier passkey del sistema.
     * 3. **La policy** (R25), como segunda barrera y punto único de la regla.
     */
    public function deletePasskey(int $passkeyId, AuthPasskeyDeleteAction $deletePasskey): void
    {
        $user = $this->currentUser();

        abort_if($this->passwordConfirmationExpired(), 423, __('Confirma tu contraseña para continuar.'));

        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        abort_unless($passkey instanceof Passkey, 404);

        $this->authorize('delete', $passkey);

        $deletePasskey->handle($user, $passkey);

        unset($this->passkeys);

        $this->toast()
            ->success(__('¡Listo!'), __('Passkey eliminada.'))
            ->send();
    }

    /**
     * Las passkeys del usuario, como DTOs (R30).
     *
     * @return array<int, PasskeyData>
     */
    #[Computed]
    public function passkeys(): array
    {
        return $this->currentUser()
            ->passkeys()
            ->latest('id')
            ->get()
            ->map(fn (Passkey $passkey): PasskeyData => new PasskeyData(
                id: (int) $passkey->id,
                name: $passkey->name,
                authenticator: $passkey->authenticator,
                createdAt: $passkey->created_at?->isoFormat('LL') ?? '',
                lastUsedAt: $passkey->last_used_at?->isoFormat('LL'),
            ))
            ->all();
    }

    public function render(): View
    {
        return view('auth::livewire.passkeys')
            ->layout('components.layouts.app', ['title' => __('Passkeys')]);
    }

    /**
     * ¿Ha caducado la confirmación de contraseña de esta sesión?
     *
     * Misma cuenta que hace `Illuminate\Auth\Middleware\RequirePassword`, que
     * no expone la comprobación: `auth.password_confirmed_at` contra
     * `auth.password_timeout` (3 h por defecto).
     */
    private function passwordConfirmationExpired(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return (time() - $confirmedAt) > (int) config('auth.password_timeout', 10800);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
