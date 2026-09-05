<?php

declare(strict_types=1);

namespace App\Core\Rules;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Enums\SystemRole;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Nadie asigna un rol más poderoso que él mismo.
 *
 * Se mide en PERMISOS, no en nombres de rol: el actor puede asignar un rol si
 * y sólo si ya posee todos los permisos que ese rol concede. Así, un rol nuevo
 * inventado por un proyecto derivado queda cubierto sin tocar esta regla, y un
 * rol vacío se puede asignar sin ser administrador.
 *
 * El superadmin la salta. El actor se pasa por constructor: nada de `auth()`
 * dentro de la regla.
 *
 * ## Por qué vive en Core y no en Users
 *
 * Porque hay **dos** formularios que reparten un rol: el de usuarios
 * (`Users\Forms\UserForm` y su gemelo de la API) y el de códigos de invitación
 * (`Auth\Forms\InvitationForm`), donde el rol viaja dentro del código y se
 * aplica al canjearlo. Auth no puede importar de Users ni Users de Auth (R5),
 * así que la única casa compartida es el kernel — y la regla ya sólo depende de
 * cosas de Core: el contrato `AuthorizationCatalog`, el enum `SystemRole` y el
 * `User` global.
 */
final readonly class GrantableRole implements ValidationRule
{
    public function __construct(
        private ?User $actor,
        private AuthorizationCatalog $catalog,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->actor instanceof User) {
            $fail(__('No hay un usuario autenticado que pueda asignar roles.'));

            return;
        }

        if ($this->actor->hasRole(SystemRole::Superadmin->value)) {
            return;
        }

        if (! is_string($value)) {
            return;
        }

        $missing = array_values(array_filter(
            $this->catalog->permissionsForRole($value),
            fn (string $permission): bool => ! $this->actorHas($permission),
        ));

        if ($missing !== []) {
            $fail(__('No puedes asignar el rol «:role»: concede permisos que tú no tienes (:missing).', [
                'role' => $value,
                'missing' => implode(', ', $missing),
            ]));
        }
    }

    private function actorHas(string $permission): bool
    {
        try {
            return $this->actor instanceof User && $this->actor->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
