<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Una tarjeta de cifras del dashboard.
 *
 * Existe para que la vista no toque Eloquent: el componente Livewire cuenta y
 * la blade sólo pinta `label`, `value` e `icon` (nombre de icono de koreUi).
 */
final class DashboardStatData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly int $value,
        public readonly string $icon,
    ) {}
}
