<?php

declare(strict_types=1);

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Un usuario ha sido borrado.
 *
 * El modelo que viaja en el evento ya no existe en la base de datos: sirve
 * para leer sus atributos (id, email) desde un listener, no para persistirlo.
 */
final readonly class UserDeleted
{
    public function __construct(
        public User $user,
    ) {}
}
