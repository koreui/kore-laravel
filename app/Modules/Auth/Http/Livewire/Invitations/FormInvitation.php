<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire\Invitations;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Data\Authorization\RoleOptionData;
use App\Models\User;
use App\Modules\Auth\Actions\InvitationCreateAction;
use App\Modules\Auth\Forms\InvitationForm;
use App\Modules\Auth\Models\InvitationCode;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Alta de un código de invitación.
 *
 * **No redirige al guardar, y ésa es la decisión de diseño de la pantalla.** El
 * código sólo se puede leer aquí —no hay pantalla de detalle, ni se vuelve a
 * mostrar en la tabla en grande— así que mandar al listado obligaría a
 * buscarlo entre las filas justo cuando hace falta copiarlo. En su lugar la
 * pantalla se queda y enseña el código con `<x-kore::clipboard>`.
 */
#[Layout('layouts.app')]
final class FormInvitation extends Component
{
    use InteractsWithFeedback;

    public InvitationForm $form;

    /**
     * El código recién creado, para copiarlo. `#[Locked]` porque manda lo que
     * se pinta en pantalla: sin el candado, el cliente podría reescribirlo y
     * hacerle creer a quien mira que el código es otro (R24).
     */
    #[Locked]
    public ?string $createdCode = null;

    public function mount(): void
    {
        $this->authorize('create', InvitationCode::class);

        $roles = $this->roles();

        $this->form->role = (string) ($roles[0]['value'] ?? '');
    }

    /**
     * Autoriza → valida → DTO → Action.
     *
     * El `authorize()` va aquí y no sólo en `mount()`: la llamada viaja por
     * `/livewire/update`, donde el `permission:invitations.manage` de la ruta
     * no corre (R23).
     */
    public function save(InvitationCreateAction $createInvitation): void
    {
        $this->authorize('create', InvitationCode::class);

        $this->form->validate();

        /** @var User $actor */
        $actor = auth()->user();

        $invitation = $createInvitation->handle($this->form->toData(), $actor);

        $this->createdCode = $invitation->code;

        $this->toast()
            ->success(__('¡Listo!'), __('Código de invitación creado.'))
            ->send();
    }

    /**
     * Roles que puede llevar un código, ya serializados para
     * `<x-kore::select :options>`.
     *
     * Llegan por el contrato de Core, que excluye superadmin: un código es una
     * credencial que se reparte por mensajería, y el rol con bypass total del
     * `Gate::before` no viaja así (R26).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function roles(): array
    {
        return array_map(
            fn (RoleOptionData $role): array => $role->toArray(),
            resolve(AuthorizationCatalog::class)->assignableRoles(),
        );
    }

    public function render(): mixed
    {
        return view('auth::livewire.invitations.form-invitation');
    }
}
