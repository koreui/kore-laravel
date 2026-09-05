<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Alta de un código de invitación, ya validada por `Auth\Forms\InvitationForm`.
 *
 * `expiresAt` viaja como cadena ISO 8601 y no como `CarbonImmutable` a
 * propósito: un DTO del boilerplate sólo depende de datos (R8), y PHPat
 * prohíbe Carbon aquí. Quien lo convierte a fecha es la Action, que es la que
 * escribe en la base.
 *
 * `code` es opcional: nulo significa «genera uno», que es el caso normal desde
 * la pantalla. Se admite escribirlo a mano para el derivado que reparte códigos
 * legibles («SOPORTE-2026»).
 */
final class InvitationData extends Data
{
    public function __construct(
        public readonly string $role,
        public readonly ?string $code = null,
        public readonly ?int $maxUses = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $note = null,
    ) {}
}
