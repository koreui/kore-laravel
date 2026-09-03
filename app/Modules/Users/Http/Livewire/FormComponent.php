<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Users\Actions\UserCreateAction;
use App\Modules\Users\Actions\UserUpdateAction;
use App\Modules\Users\Forms\UserForm;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
final class FormComponent extends Component
{
    use InteractsWithFeedback;

    #[Locked]
    public ?User $model = null;

    public UserForm $form;

    public function mount(): void
    {
        if (! $this->model instanceof User) {
            $this->authorize('create', User::class);

            return;
        }

        $this->authorize('update', $this->model);

        $firstRole = $this->model->roles->first();
        $roleName = $firstRole !== null ? (string) $firstRole->getAttribute('name') : SystemRole::User->value;

        $this->form->fill([
            'id' => $this->model->id,
            'name' => $this->model->name,
            'email' => $this->model->email,
            'role' => $roleName,
            'permissions' => $this->model->getDirectPermissions()->pluck('name')->all(),
        ]);
    }

    /**
     * Autoriza → valida → DTO → Action. Nada más: la escritura vive en la
     * Action y este método sólo orquesta.
     *
     * Las Actions llegan por inyección de método (Livewire las resuelve del
     * contenedor), que es lo que permite fakearlas en un test sin tocar el
     * componente.
     *
     * Las rutas de `users` llevan middleware `permission:*`, pero las llamadas
     * Livewire viajan por /livewire/update, donde ese middleware NO corre. Por
     * eso la autorización tiene que vivir dentro del componente.
     */
    public function save(UserCreateAction $createUser, UserUpdateAction $updateUser): mixed
    {
        if ($this->model instanceof User) {
            $this->authorize('update', $this->model);
        } else {
            $this->authorize('create', User::class);
        }

        $this->form->validate();

        $data = $this->form->toData();

        $user = $this->model instanceof User
            ? $updateUser->handle($this->model, $data)
            : $createUser->handle($data);

        $this->form->id = $user->id;
        $this->form->password = null;
        $this->form->password_confirmation = null;

        $this->toast()
            ->success(__('¡Listo!'), __('Usuario guardado correctamente.'))
            ->viaSession()
            ->send();

        return to_route('users.index');
    }

    #[Computed]
    public function title(): string
    {
        return $this->model instanceof User ? __('Editar usuario') : __('Crear usuario');
    }

    /**
     * Roles asignables, serializados a `{value, label}` para
     * `<x-kore::select :options>`.
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

    /**
     * Estructura de módulos para el editor de permisos. Cada item tiene
     * `module`, `permissions` (lista de {value,label}) y `roles` (metadata
     * usada por Alpine para auto-seleccionar al elegir un rol).
     *
     * Llega por el contrato de Core: el módulo Users no conoce `Module` ni
     * `Role` (regla 3 de CLAUDE.md).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function modules(): array
    {
        return array_map(
            fn (PermissionModuleData $module): array => $module->toArray(),
            resolve(AuthorizationCatalog::class)->permissionModules(),
        );
    }

    public function render(): mixed
    {
        return view('users::livewire.form-component');
    }
}
