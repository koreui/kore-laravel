<?php

declare(strict_types=1);

namespace App\Core\Data\Authorization;

use App\Core\Data\Data;

/**
 * Un rol asignable, en la forma que consumen los `<x-kore::select :options>`
 * del boilerplate: `{value, label}`.
 */
final class RoleOptionData extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
