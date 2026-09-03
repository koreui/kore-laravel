<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Una cuenta de demostración del switcher de desarrollo.
 *
 * Existe para que la vista no toque Eloquent (R30): el componente Livewire
 * consulta y la blade sólo pinta lo que hay aquí.
 */
final class DevAccountData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly bool $isCurrent,
    ) {}
}
