<?php

declare(strict_types=1);

namespace App\Modules\Devices\Data;

use App\Core\Data\Data;

/**
 * El token de notificaciones que un dispositivo acaba de obtener de su
 * proveedor (FCM, APNs, Web Push).
 *
 * Un solo campo y aun así un DTO (R8): es lo que hace que
 * `DevicePushTokenUpdateAction` siga teniendo la misma firma el día que el
 * proveedor pida además el `sandbox` o el `bundle_id`, en vez de crecer una
 * lista de argumentos sueltos.
 *
 * El boilerplate **no valida el token contra ningún proveedor**: lo guarda. Un
 * token inválido se descubre al enviar, y descubrirlo aquí costaría una llamada
 * de red por petición (R22) en un endpoint que sólo escribe una columna.
 */
final class DevicePushTokenData extends Data
{
    public function __construct(
        public readonly string $pushToken,
    ) {}
}
