<?php

declare(strict_types=1);

namespace App\Modules\Devices\Listeners;

use App\Modules\Auth\Events\ApiTokenRevoked;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;

/**
 * Un token deja de valer, su dispositivo deja de contar como activo.
 *
 * `ApiTokenRevoked::$tokenId` es `null` cuando se revocan **todos** los tokens
 * del usuario a la vez —«cerrar sesión en todas partes», un cambio de
 * contraseña, una cuenta comprometida—, y entonces caen todos sus dispositivos.
 * Con un id concreto cae sólo el que colgaba de ese token.
 *
 * Aquí no se borra ningún token: quien lo hace es Auth, que es quien lo emitió.
 * Este listener sólo mantiene el inventario al día, y por eso puede llegar
 * tarde, repetirse o no existir (con `DEVICES_ENABLED=false`) sin que el logout
 * cambie de comportamiento.
 */
final class RevokeDeviceOnTokenRevoked
{
    public function handle(ApiTokenRevoked $event): void
    {
        $devices = Device::query()
            ->where('user_id', $event->user->getKey())
            ->active();

        if ($event->tokenId !== null) {
            $devices->where('access_token_id', $event->tokenId);
        }

        $devices->update(['revoked_at' => CarbonImmutable::now()]);
    }
}
