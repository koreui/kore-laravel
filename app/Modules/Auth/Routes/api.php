<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Module — API routes (Sanctum)
|--------------------------------------------------------------------------
|
| Solo se cargan cuando API_ENABLED=true (ver AuthModuleServiceProvider).
|
| Prefijo `api/v1` y nombres `api.v1.*`: la versión es parte de la URL, no una
| cabecera, para que un cliente viejo siga funcionando cuando salga la v2 y
| para que la documentación (Scramble) sepa qué documentar —sólo mira `api/v*`.
| El segmento sale de `config('kore-api.version')`.
|
| Todo endpoint pasa por el contrato de `App\Core\Http\Api` (R54): controller
| que extiende `ApiController`, respuesta en `{ data, meta? }` y errores en
| `{ error: { code, message, details? } }`. Ver docs/guides/api.md.
|
*/

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.config('kore-api.version', 'v1'))
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/user', [UserController::class, 'me'])->name('user.me');
    });
