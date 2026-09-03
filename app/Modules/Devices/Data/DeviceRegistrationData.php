<?php

declare(strict_types=1);

namespace App\Modules\Devices\Data;

use App\Core\Data\Data;
use App\Modules\Devices\Enums\Platform;

/**
 * Lo que hace falta para dar de alta (o refrescar) un dispositivo.
 *
 * Es el contrato entre quien observa el alta —hoy
 * `RegisterDeviceOnTokenIssued`, escuchando a Auth— y `DeviceRegisterAction`.
 * El usuario **no** viaja aquí: R19 lo pasa por parámetro a la Action, y R8
 * mantiene el DTO como un dato puro (PHPat sólo le deja depender de `Core\Data`,
 * `Core\Enums` y enums).
 *
 * Todo es opcional menos `deviceId` porque un cliente de API no tiene por qué
 * ser un móvil: un CLI manda su identificador y nada más.
 */
final class DeviceRegistrationData extends Data
{
    public function __construct(
        public readonly string $deviceId,
        public readonly ?string $name = null,
        public readonly ?Platform $platform = null,
        public readonly ?string $appVersion = null,
        public readonly ?int $accessTokenId = null,
    ) {}
}
