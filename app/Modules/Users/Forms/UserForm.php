<?php

declare(strict_types=1);

namespace App\Modules\Users\Forms;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Livewire Form Object para crear/editar usuarios.
 *
 * Convención del boilerplate (ver docs/guides/crud/livewire-form.md):
 * - $id nullable se usa para updateOrCreate
 * - rules() devuelve array (no atributos)
 * - store() ejecuta updateOrCreate y retorna el modelo
 *
 * Aparte del modelo, el form maneja role (string) y permissions (array)
 * que se aplican post-save via syncRoles + syncPermissions.
 */
class UserForm extends Form
{
    public ?int $id = null;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public string $role = Role::USER;

    /** @var array<int, string> */
    public array $permissions = [];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->id)],
            'password' => [
                $this->id ? 'nullable' : 'required',
                'string',
                'min:8',
                'confirmed',
            ],
            'role' => ['required', Rule::in(Role::assignableNames())],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function store(): User
    {
        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->password) {
            $attributes['password'] = Hash::make($this->password);
        }

        if (! $this->id) {
            $attributes['email_verified_at'] = now();
        }

        $user = User::updateOrCreate(['id' => $this->id], $attributes);

        $user->syncRoles([$this->role]);
        $user->syncPermissions($this->permissions);

        $this->id = $user->id;
        $this->password = null;
        $this->password_confirmation = null;

        return $user;
    }
}
