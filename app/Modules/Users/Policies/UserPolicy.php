<?php

declare(strict_types=1);

namespace App\Modules\Users\Policies;

use App\Core\Enums\SystemRole;
use App\Models\User;

/**
 * Policy del módulo Users.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve
 * true para el rol superadmin, así que para ese rol la policy NUNCA se
 * evalúa. Las guardas que deben aplicar incluso al superadmin (p. ej. no
 * borrarse a sí mismo) se repiten en el componente Livewire.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Sólo un superadmin puede editar a otro superadmin.
     */
    public function update(User $user, User $target): bool
    {
        if ($target->hasRole(SystemRole::Superadmin->value) && ! $user->hasRole(SystemRole::Superadmin->value)) {
            return false;
        }

        return $user->can('users.edit');
    }

    /**
     * Bloquea el auto-borrado y el borrado de cualquier superadmin, incluso si
     * quien lo intenta tiene el permiso `users.delete`.
     */
    public function delete(User $user, User $target): bool
    {
        if ($target->id === $user->id) {
            return false;
        }

        if ($target->hasRole(SystemRole::Superadmin->value)) {
            return false;
        }

        return $user->can('users.delete');
    }
}
