<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests\Api\V1;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Http\Api\Requests\BaseApiRequest;
use App\Core\Rules\GrantableRole;
use App\Models\User;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Rules\GrantablePermission;
use Illuminate\Validation\Rule;

/**
 * Lo que comparten el alta y la edición de un usuario por API.
 *
 * Es el `UserForm` de Livewire visto desde el otro lado: mismas reglas, mismo
 * `toData()`, mismo DTO de salida. Que sean dos clases y no una es lo que
 * permite que la API y la pantalla evolucionen por separado; que compartan
 * `GrantableRole` y `GrantablePermission` es lo que **impide** que evolucionen
 * hasta dejar de proteger lo mismo.
 *
 * **R26 por API.** Sin estas dos reglas, `POST /api/v1/users` sería la puerta
 * de atrás de la escalada de privilegios que la v1.1.0 cerró en el formulario:
 * cualquiera con `users.create` podría crear una cuenta con permisos que él no
 * tiene y entrar con ella. Un token con la ability `users.create` no es un
 * permiso para repartir cualquier otro.
 *
 * El actor sale de `$this->user()` y **no** de `auth()`: dentro de un
 * `FormRequest` estamos en la capa Http, pero las Rules reciben el usuario por
 * constructor para poder ejecutarse desde consola (R19).
 */
abstract class UserApiRequest extends BaseApiRequest
{
    /**
     * Reglas comunes. La contraseña y el `unique` del email los completa cada
     * subclase, porque son justo lo que cambia entre crear y editar.
     *
     * @return array<string, list<mixed>>
     */
    protected function sharedRules(): array
    {
        $catalog = resolve(AuthorizationCatalog::class);
        $actor = $this->actor();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->userId())],
            'role' => [
                'required',
                Rule::in($catalog->assignableRoleNames()),
                new GrantableRole($actor, $catalog),
            ],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                'exists:permissions,name',
                new GrantablePermission($actor),
            ],
        ];
    }

    /**
     * Estado validado como DTO, exactamente el mismo que produce
     * `UserForm::toData()`, para que las Actions no sepan por dónde entró el
     * dato (R8).
     */
    public function toData(): UserData
    {
        /** @var array<int, string> $permissions */
        $permissions = (array) ($this->validated('permissions') ?? []);
        $password = $this->validated('password');

        return new UserData(
            name: (string) $this->validated('name'),
            email: (string) $this->validated('email'),
            password: is_string($password) && $password !== '' ? $password : null,
            role: (string) $this->validated('role'),
            permissions: array_values(array_map(strval(...), $permissions)),
        );
    }

    /**
     * El usuario que se edita, o `null` al crear. Es lo que hace que el
     * `unique` del email no choque consigo mismo en un PUT.
     */
    protected function userId(): ?int
    {
        $user = $this->route('user');

        return $user instanceof User ? $user->id : null;
    }

    private function actor(): ?User
    {
        $actor = $this->user();

        return $actor instanceof User ? $actor : null;
    }
}
