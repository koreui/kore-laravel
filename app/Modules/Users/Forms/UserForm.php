<?php

declare(strict_types=1);

namespace App\Modules\Users\Forms;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Enums\SystemRole;
use App\Core\Rules\GrantableRole;
use App\Models\User;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Rules\GrantablePermission;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Livewire Form Object para crear/editar usuarios.
 *
 * Convención del boilerplate (ver docs/guides/crud.md):
 * - `$id` nullable distingue "crear" de "editar"
 * - `rules()` devuelve array (no atributos)
 * - `toData()` empaqueta el estado validado en el DTO que consumen las Actions
 *
 * El form NO persiste: valida y traduce. Escribir es trabajo de
 * `UserCreateAction` / `UserUpdateAction` (regla 1 de CLAUDE.md), que así
 * sirven igual desde un job o un comando artisan.
 *
 * SEGURIDAD: `$id` va con #[Locked]. Sin ese candado un cliente con permiso
 * `users.create` podía mandar `form.id` por /livewire/update y sobrescribir a
 * CUALQUIER usuario (email, password, rol y permisos incluidos). El candado
 * sólo bloquea escrituras del cliente: el mount() del componente sigue
 * pudiendo asignarlo vía fill() (data_set).
 */
final class UserForm extends Form
{
    #[Locked]
    public ?int $id = null;

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public string $role = SystemRole::User->value;

    /** @var array<int, string> */
    public array $permissions = [];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $catalog = resolve(AuthorizationCatalog::class);
        $actor = auth()->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->id)],
            'password' => [
                $this->id !== null ? 'nullable' : 'required',
                'string',
                'min:8',
                'confirmed',
            ],
            'role' => [
                'required',
                Rule::in($catalog->assignableRoleNames()),
                new GrantableRole($actor instanceof User ? $actor : null, $catalog),
            ],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                'exists:permissions,name',
                new GrantablePermission($actor instanceof User ? $actor : null),
            ],
        ];
    }

    /**
     * Estado del formulario como DTO para las Actions.
     *
     * Llamar SIEMPRE después de `validate()`: `UserData` no valida nada.
     */
    public function toData(): UserData
    {
        return new UserData(
            name: $this->name,
            email: $this->email,
            password: $this->password === '' ? null : $this->password,
            role: $this->role,
            permissions: array_values($this->permissions),
        );
    }
}
