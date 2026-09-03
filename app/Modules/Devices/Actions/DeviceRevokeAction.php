<?php

declare(strict_types=1);

namespace App\Modules\Devices\Actions;

use App\Core\Actions\Action;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revoca un dispositivo **y su token de Sanctum**.
 *
 * Las dos mitades son la misma operación: marcar la fila y dejar el token vivo
 * sería un botón de «cerrar sesión en este dispositivo» que no cierra ninguna
 * sesión — el teléfono seguiría llamando a la API con el mismo bearer hasta que
 * caducara. Por eso van juntas y dentro de una transacción: un fallo a medias
 * deja o un dispositivo revocado con token válido, o un token borrado sin
 * rastro de por qué.
 *
 * Si el dispositivo revocado es el que hace la petición, el token que se borra
 * es el de esa misma petición: el 204 se responde y la siguiente llamada del
 * cliente es un 401. No hace falta ningún caso especial para eso.
 *
 * La fila **no** se borra: la revocación es lo que permite responder «¿desde
 * qué dispositivo se entró?» durante una investigación. Quien la borra, pasado
 * `devices.prune_after_days`, es `devices:cleanup`.
 */
final class DeviceRevokeAction extends Action
{
    public function handle(Device $device): Device
    {
        DB::transaction(function () use ($device): void {
            $tokenId = $device->access_token_id;

            $device->update(['revoked_at' => CarbonImmutable::now()]);

            if ($tokenId !== null) {
                PersonalAccessToken::query()->whereKey($tokenId)->delete();
            }
        });

        return $device;
    }
}
