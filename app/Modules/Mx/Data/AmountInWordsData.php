<?php

declare(strict_types=1);

namespace App\Modules\Mx\Data;

use App\Core\Data\Data;

/**
 * Un importe y su forma en letra.
 *
 * `amount` es el importe **ya redondeado a dos decimales**, que es el que se
 * convirtió: devolverlo junto a la letra evita la discusión de si `1234.567` se
 * escribió como 56 o como 57 centavos.
 */
final class AmountInWordsData extends Data
{
    public function __construct(
        public readonly float $amount,
        public readonly string $words,
    ) {}
}
