<?php

declare(strict_types=1);

namespace App\Modules\Devices\Support;

use App\Core\Contracts\PushTokenDirectory;
use App\Modules\Devices\Models\Device;

/**
 * El inventario de Devices, visto por quien manda notificaciones push.
 *
 * Es la implementación de `App\Core\Contracts\PushTokenDirectory`, y la única
 * razón por la que el módulo Notifications puede saber a qué teléfonos mandar
 * algo sin importar `Device` ni conocer la tabla (R5). Toda la relación entre
 * los dos módulos son estas veinte líneas y una interfaz de Core.
 *
 * El binding lo pone `DevicesModuleServiceProvider::register()` y **sólo con
 * `DEVICES_ENABLED=true`**: sin inventario no hay directorio, y quien lo
 * consume pregunta antes por `bound()` en vez de resolverlo a ciegas.
 *
 * Dos filtros, y los dos importan:
 *
 * - **`active()`** — un dispositivo revocado es un teléfono vendido, perdido o
 *   cuya sesión alguien cerró a propósito. Mandarle un push sería seguir
 *   hablándole al aparato del que se quiso desconectar.
 * - **`unique`** — dos filas pueden compartir token cuando alguien reinstala la
 *   app y el servicio de push le devuelve el mismo identificador. Sin esto, esa
 *   persona recibiría el aviso por duplicado.
 */
final class DevicePushTokens implements PushTokenDirectory
{
    /**
     * @return array<int, string>
     */
    public function tokensFor(int $userId): array
    {
        return array_values(array_unique(
            Device::query()
                ->where('user_id', $userId)
                ->active()
                ->whereNotNull('push_token')
                ->pluck('push_token')
                ->map(strval(...))
                ->filter(static fn (string $token): bool => $token !== '')
                ->all(),
        ));
    }
}
