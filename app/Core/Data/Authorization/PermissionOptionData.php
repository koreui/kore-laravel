<?php

declare(strict_types=1);

namespace App\Core\Data\Authorization;

use App\Core\Data\Data;

/**
 * Un permiso concreto (`{slug}.{accion}`) con su etiqueta para la UI.
 */
final class PermissionOptionData extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
