<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Quién pide el token, desde el punto de vista de Auth.
 *
 * `name` es obligatorio y es el **nombre del token**: es lo único que le queda
 * al usuario para decidir cuál revocar desde una pantalla de «mis sesiones».
 * Cinco filas llamadas `api` no son una lista de dispositivos, son una lista de
 * nadas — y por eso aquí no hay default, a diferencia de asper-server, que cae
 * al user-agent y acaba con tres tokens llamados `okhttp/4.12.0`.
 *
 * Los otros tres son opcionales porque un cliente de API no tiene por qué ser
 * un móvil (un cron, un CLI, un servicio). Auth **no los persiste**: viajan
 * dentro de `ApiTokenIssued` para que el módulo que lleve el registro de
 * dispositivos los use sin que Auth sepa que existe (R5). Notarium hace esto
 * mismo con una tabla `mobile_devices`, pero la escribe dentro del propio
 * controller de login: el evento es lo que permite tenerla sin acoplar.
 */
final class ApiDeviceData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $id = null,
        public readonly ?string $platform = null,
        public readonly ?string $appVersion = null,
    ) {}
}
