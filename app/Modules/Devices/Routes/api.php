<?php

declare(strict_types=1);

use App\Modules\Devices\Http\Controllers\Api\V1\DeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Devices Module — API routes (Sanctum)
|--------------------------------------------------------------------------
|
| Se cargan sólo con los DOS toggles encendidos: `DEVICES_ENABLED` (el módulo)
| y `API_ENABLED` (la API). Lo decide DevicesModuleServiceProvider.
|
| Mismo contrato que el resto de la API (R54): prefijo `api/v1`, nombres
| `api.v1.*`, controller que extiende `ApiController` y errores por
| `ApiExceptionRenderer`. Ver docs/guides/api.md y docs/modules/devices.md.
|
| `push-token` va ANTES del `{device:uuid}` por costumbre, aunque aquí no haga
| falta: son verbos distintos (PUT vs DELETE) y profundidades distintas, así que
| el router no puede confundirlos.
|
| El middleware `devices.version` NO se aplica aquí: es opt-in por ruta y esta
| pantalla es justo la que un cliente desactualizado necesita poder abrir para
| cerrar la sesión de un dispositivo perdido.
|
*/

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.config('kore-api.version', 'v1'))
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/devices', [DeviceController::class, 'index'])
            ->name('devices.index');

        Route::put('/devices/current/push-token', [DeviceController::class, 'updatePushToken'])
            ->name('devices.push-token.update');

        Route::delete('/devices/{device:uuid}', [DeviceController::class, 'destroy'])
            ->name('devices.destroy');
    });
