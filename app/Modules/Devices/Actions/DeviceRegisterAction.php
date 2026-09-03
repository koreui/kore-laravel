<?php

declare(strict_types=1);

namespace App\Modules\Devices\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Devices\Data\DeviceRegistrationData;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;

/**
 * Alta o refresco de un dispositivo del usuario.
 *
 * `updateOrCreate` sobre `[user_id, device_id]` y no `create`: el mismo teléfono
 * entra muchas veces y lo que interesa es una fila por dispositivo con el último
 * token, no una fila por sesión. La clave compuesta la garantiza el índice único
 * de la migración, así que dos logins simultáneos del mismo cliente no producen
 * dos filas.
 *
 * `revoked_at` vuelve a `null` a propósito: volver a entrar es exactamente lo
 * que resucita un dispositivo que se había revocado. Si no se limpiara, el
 * dispositivo quedaría con token válido y marcado como revocado, que es el peor
 * de los dos mundos.
 *
 * El actor llega por parámetro (R19): así la Action sirve igual desde el
 * listener de `ApiTokenIssued`, desde un comando o desde un seeder.
 */
final class DeviceRegisterAction extends Action
{
    public function handle(User $user, DeviceRegistrationData $data): Device
    {
        return Device::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'device_id' => $data->deviceId,
            ],
            [
                'name' => $data->name,
                'platform' => $data->platform,
                'app_version' => $data->appVersion,
                'access_token_id' => $data->accessTokenId,
                'last_seen_at' => CarbonImmutable::now(),
                'revoked_at' => null,
            ],
        );
    }
}
