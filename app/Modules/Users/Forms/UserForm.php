<?php

declare(strict_types=1);

namespace App\Modules\Users\Forms;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Livewire Form Object para crear/editar usuarios.
 *
 * Convención del boilerplate (ver docs/guides/crud.md):
 * - $id nullable distingue "crear" de "editar"
 * - rules() devuelve array (no atributos)
 * - store() resuelve el modelo, lo guarda y lo retorna
 *
 * Aparte del modelo, el form maneja role (string) y permissions (array)
 * que se aplican post-save via syncRoles + syncPermissions.
 *
 * SEGURIDAD: `$id` va con #[Locked]. Sin ese candado un cliente con permiso
 * `users.create` podía mandar `form.id` por /livewire/update y hacer que el
 * updateOrCreate sobrescribiera a CUALQUIER usuario (email, password, rol y
 * permisos incluidos). El candado sólo bloquea escrituras del cliente: el
 * mount() del componente sigue pudiendo asignarlo vía fill() (data_set).
 */
final class UserForm extends Form
{
    #[Locked]
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

    /**
     * El modelo se resuelve explícitamente en vez de con
     * `updateOrCreate(['id' => $this->id], ...)`: sin `Model::unguard()` global
     * ese patrón revienta al crear (el `id` no es mass-assignable) y además
     * dejaba la puerta abierta a sobrescribir cualquier usuario.
     */
    public function store(): User
    {
        $user = $this->id !== null
            ? User::findOrFail($this->id)
            : new User;

        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->password !== null && $this->password !== '') {
            $attributes['password'] = Hash::make($this->password);
        }

        if (! $user->exists) {
            $attributes['email_verified_at'] = now();
        }

        $user->fill($attributes)->save();

        $user->syncRoles([$this->role]);
        $user->syncPermissions($this->permissions);

        $this->id = $user->id;
        $this->password = null;
        $this->password_confirmation = null;

        return $user;
    }
}
