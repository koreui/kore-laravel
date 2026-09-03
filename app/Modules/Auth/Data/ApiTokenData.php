<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Un token recién emitido, tal y como sale de `AuthApiTokenIssueAction`.
 *
 * `plainTextToken` es el **único** momento de su vida en el que el token existe
 * en claro: la base guarda un `hash('sha256', …)` y no hay forma de volver a
 * enseñárselo a nadie. Por eso viaja en un DTO y no en el modelo
 * `PersonalAccessToken`, que no lo tiene.
 *
 * `abilities` son los permisos efectivos del usuario en el instante de la
 * emisión, congelados. Que se queden viejos es exactamente el agujero que cierra
 * `RevokeApiTokensOnPermissionChange` (R26 llevado a la API).
 *
 * `expiresAt` viaja ya formateado en ISO 8601 (o `null` si el token no caduca),
 * como el `createdAt` de `PasskeyData`: un DTO sólo depende de datos, no de
 * `CarbonImmutable` (R8), y quien lo consume —un resource, una vista— lo único
 * que iba a hacer con la fecha era formatearla.
 */
final class ApiTokenData extends Data
{
    /**
     * @param array<int, string> $abilities
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $plainTextToken,
        public readonly ?string $expiresAt,
        public readonly array $abilities,
    ) {}
}
