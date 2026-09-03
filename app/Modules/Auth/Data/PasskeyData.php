<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Una passkey tal y como la pinta `/user/passkeys`.
 *
 * R30 · la vista no toca Eloquent: el componente arma estos DTOs desde la
 * relación del usuario y las fechas llegan ya formateadas. Y sólo lo que la
 * pantalla necesita: ni `credential_id` ni `credential` (la clave pública y sus
 * metadatos) salen del servidor.
 */
final class PasskeyData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        /** Modelo del autenticador según su AAGUID («iCloud Keychain», «1Password»…). */
        public readonly ?string $authenticator,
        public readonly string $createdAt,
        public readonly ?string $lastUsedAt,
    ) {}
}
