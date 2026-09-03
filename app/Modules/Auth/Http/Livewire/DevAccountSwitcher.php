<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire;

use App\Models\User;
use App\Modules\Auth\Actions\AuthDevImpersonateUserAction;
use App\Modules\Auth\Data\DevAccountData;
use App\Modules\Auth\Support\DemoAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Switcher de cuentas de demostración, en `/dev/switch-account`.
 *
 * Lista las cuentas que siembran `DatabaseSeeder` (`admin@example.com`) y
 * `E2eSeeder` (`superadmin@`, `editor@`, `viewer@`, `member@e2e.test`) y deja
 * entrar como cualquiera de ellas sin pasar por el formulario. Probar «qué ve
 * un viewer» deja de ser cerrar sesión, recordar la contraseña y volver a
 * entrar: es un clic.
 *
 * Sólo existe en `local`, y por triplicado: `AuthModuleServiceProvider` no
 * registra ni la ruta ni el componente fuera de local, el `abort` de abajo
 * corta la llamada por `/livewire/update` —que no pasa por el middleware de la
 * ruta (R23)— y {@see AuthDevImpersonateUserAction} vuelve a comprobarlo antes
 * de tocar la sesión.
 */
final class DevAccountSwitcher extends Component
{
    /**
     * Entra como la cuenta elegida.
     *
     * La regeneración del identificador de sesión vive aquí y no en la Action:
     * `session()` es de la capa Http (R19). Y la redirección va con
     * `navigate: false` a propósito — un `wire:navigate` reutilizaría el
     * documento y el layout se repintaría con el usuario anterior en la barra.
     */
    public function switchTo(int $userId, AuthDevImpersonateUserAction $impersonate): void
    {
        abort_unless(App::isLocal(), 403);

        $target = User::query()->findOrFail($userId);

        $impersonate->handle($target);

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: false);
    }

    /**
     * Cuentas de demostración, agrupadas por rol.
     *
     * `#[Computed]` para que la consulta corra una vez por render. Fuera de
     * local devuelve vacío: la pantalla no existe, pero el componente tampoco
     * enseña nada si alguien lo monta de otra forma.
     *
     * @return array<string, list<DevAccountData>>
     */
    #[Computed]
    public function accountsByRole(): array
    {
        if (! App::isLocal()) {
            return [];
        }

        $currentId = auth()->id();

        /** @var array<string, list<DevAccountData>> $grouped */
        $grouped = [];

        $users = User::query()
            ->with('roles')
            ->where(function (Builder $query): void {
                foreach (DemoAccounts::likePatterns() as $pattern) {
                    $query->orWhere('email', 'like', $pattern);
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $role = (string) ($user->getRoleNames()->first() ?? __('Sin rol'));

            $grouped[$role][] = new DevAccountData(
                id: $user->id,
                name: $user->name,
                email: $user->email,
                role: $role,
                isCurrent: $user->id === $currentId,
            );
        }

        ksort($grouped);

        return $grouped;
    }

    public function render(): View
    {
        return view('auth::livewire.dev-account-switcher')
            ->layout('components.layouts.app', ['title' => __('Cambiar de cuenta')]);
    }
}
