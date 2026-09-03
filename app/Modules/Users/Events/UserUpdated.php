<?php

declare(strict_types=1);

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Un usuario existente ha sido actualizado (datos, rol y/o permisos).
 *
 * El modelo llega ya refrescado. Para saber qué cambió, consulta el
 * `activity_log` (spatie/laravel-activitylog registra name/email).
 */
final readonly class UserUpdated
{
    public function __construct(
        public User $user,
    ) {}
}
