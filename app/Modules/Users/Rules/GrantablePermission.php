<?php

declare(strict_types=1);

namespace App\Modules\Users\Rules;

use App\Core\Enums\SystemRole;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Nadie concede lo que no tiene.
 *
 * Sin esta regla, cualquiera con `users.create` + `users.edit` podía darle a
 * otro usuario CUALQUIER permiso del sistema —incluidos los que él mismo no
 * tiene— y luego entrar con esa cuenta: escalada de privilegios de manual.
 *
 * El superadmin la salta (su `Gate::before` ya le da todo). El actor se pasa
 * por constructor a propósito: dentro de una regla de validación no se lee
 * `auth()`, así se puede testear y reutilizar desde consola.
 */
final readonly class GrantablePermission implements ValidationRule
{
    public function __construct(
        private ?User $actor,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->actor instanceof User) {
            $fail(__('No hay un usuario autenticado que pueda conceder permisos.'));

            return;
        }

        if ($this->actor->hasRole(SystemRole::Superadmin->value)) {
            return;
        }

        if (! is_string($value)) {
            return;
        }

        try {
            $hasIt = $this->actor->hasPermissionTo($value);
        } catch (PermissionDoesNotExist) {
            // El permiso no existe: de eso ya se queja la regla `exists`.
            return;
        }

        if (! $hasIt) {
            $fail(__('No puedes conceder el permiso «:permission» porque tú no lo tienes.', ['permission' => $value]));
        }
    }
}
