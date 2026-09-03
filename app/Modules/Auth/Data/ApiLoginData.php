<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Lo que un cliente manda para pedir un token (`POST /api/v1/auth/login`).
 *
 * Credenciales + la descripción del cliente que las presenta, plano y no
 * anidado: R8 no deja que un DTO dependa de otro DTO de módulo, y esta es la
 * forma en la que el dato **llega**. El `ApiDeviceData` que consume la Action lo
 * arma el controller, que es quien traduce «lo que mandó el cliente» a «lo que
 * necesita el caso de uso».
 *
 * Sin comportamiento y sin modelos (R8): quien valida el formato es
 * `LoginRequest` y quien comprueba que la contraseña es la buena, el controller.
 */
final class ApiLoginData extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $deviceName,
        public readonly ?string $deviceId = null,
        public readonly ?string $platform = null,
        public readonly ?string $appVersion = null,
    ) {}
}
