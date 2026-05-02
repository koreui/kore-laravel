<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Models\User;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
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
            return;
        }

        $firstRole = $this->model->roles->first();
        $roleName = $firstRole !== null ? (string) $firstRole->getAttribute('name') : Role::USER;

        $this->form->fill([
            'id' => $this->model->id,
            'name' => $this->model->name,
            'email' => $this->model->email,
            'role' => $roleName,
            'permissions' => $this->model->getDirectPermissions()->pluck('name')->all(),
        ]);
    }

    public function save(): mixed
    {
        $this->form->validate();
        $this->form->store();

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
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function roles(): array
    {
        return Role::allRoles();
    }

    /**
     * Estructura de módulos para el editor de permisos. Cada item tiene
     * `module`, `permissions` (lista de {value,label}) y `roles` (metadata
     * usada por Alpine para auto-seleccionar al elegir un rol).
     *
     * @return array<int, array{module: string, permissions: array<int, array{value: string, label: string}>, roles: array<int, string>|null}>
     */
    #[Computed]
    public function modules(): array
    {
        return Module::where('active', true)->get()->permissionsToArray();
    }

    public function render(): mixed
    {
        return view('users::livewire.form-component');
    }
}
