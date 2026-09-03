<?php

declare(strict_types=1);

namespace App\Modules\Auth\Events;

use App\Models\User;

/**
 * Un token de API ha dejado de valer: logout, logout de todos los
 * dispositivos, o revocación al cambiar los permisos del usuario.
 *
 * `tokenId` es `null` cuando se revocan todos los tokens del usuario a la vez.
 */
final readonly class ApiTokenRevoked
{
    public function __construct(
        public User $user,
        public ?int $tokenId,
        public string $reason,
    ) {}
}
