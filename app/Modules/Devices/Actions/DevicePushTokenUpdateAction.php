<?php

declare(strict_types=1);

namespace App\Modules\Devices\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Devices\Data\DevicePushTokenData;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;

/**
 * Guarda el token de notificaciones del dispositivo que hace la petición.
 *
 * El dispositivo se identifica por el **token de Sanctum en uso**, no por un id
 * que mande el cliente: así una app no puede reescribir el `push_token` de otro
 * dispositivo de la misma cuenta pasando su uuid. El identificador de la fila lo
 * pone la autenticación, que es el único dato que el cliente no elige.
 *
 * Devuelve `null` cuando ese token no tiene dispositivo registrado —un token
 * creado desde el panel web, o un login sin `device_id`—. Quien decide qué
 * status HTTP es eso vive en la capa Http (R20); aquí sólo se informa de que no
 * había nada que actualizar.
 */
final class DevicePushTokenUpdateAction extends Action
{
    public function handle(User $user, ?int $accessTokenId, DevicePushTokenData $data): ?Device
    {
        if ($accessTokenId === null) {
            return null;
        }

        $device = Device::query()
            ->where('user_id', $user->getKey())
            ->where('access_token_id', $accessTokenId)
            ->active()
            ->first();

        if (! $device instanceof Device) {
            return null;
        }

        $device->update([
            'push_token' => $data->pushToken,
            'last_seen_at' => CarbonImmutable::now(),
        ]);

        return $device;
    }
}
