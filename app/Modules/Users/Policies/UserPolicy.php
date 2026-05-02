<?php

declare(strict_types=1);

namespace App\Modules\Users\Policies;

use App\Models\User;
use App\Modules\Auth\Models\Role;

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

    public function update(User $user, User $target): bool
    {
        if ($target->hasRole(Role::SUPERADMIN) && ! $user->hasRole(Role::SUPERADMIN)) {
            return false;
        }

        return $user->can('users.edit');
    }

    public function delete(User $user, User $target): bool
    {
        if ($target->id === $user->id) {
            return false;
        }

        if ($target->hasRole(Role::SUPERADMIN)) {
            return false;
        }

        return $user->can('users.delete');
    }
}
