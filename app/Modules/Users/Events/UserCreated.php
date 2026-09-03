<?php

declare(strict_types=1);

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Un usuario acaba de crearse, con su rol y permisos ya sincronizados.
 *
 * Los eventos son el canal oficial para que otros módulos reaccionen a Users
 * sin importarlo (regla 3 de CLAUDE.md): el listener vive en
 * `App\Modules\{Otro}\Listeners\` y sólo depende de esta clase.
 */
final readonly class UserCreated
{
    public function __construct(
        public User $user,
    ) {}
}
