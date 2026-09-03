<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\AuthTokenController;
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

$version = config('kore-api.version', 'v1');

/*
 * Público —sin `auth:sanctum`— y por eso con el limiter estricto.
 *
 * `throttle:api-auth` son 5 peticiones por minuto y por IP (R28): quien fuerza
 * credenciales todavía no tiene usuario, así que limitar por usuario no
 * limitaría nada. Corre por delante del controller, de modo que un intento
 * fallido cuenta igual que uno bueno — que es el punto.
 *
 * Sin middleware `guest`, que aquí sería un tiro en el pie: `RedirectIfAuthenticated`
 * responde con un **302** hacia una pantalla web, y un cliente de API que
 * reintenta el login con un token todavía válido se comería un redirect en vez
 * de una respuesta del contrato. Volver a hacer login teniendo un token es
 * legítimo: es lo que hace una app tras reinstalarse.
 */
Route::middleware(['api', 'throttle:api-auth'])
    ->prefix('api/'.$version.'/auth')
    ->name('api.v1.auth.')
    ->group(function (): void {
        Route::post('/login', [AuthTokenController::class, 'login'])->name('login');
    });

/*
 * Con token. `refresh` repite el limiter estricto: es lo único que devuelve una
 * credencial nueva y, si el token filtrado sigue vivo, es también la vía para
 * mantenerlo vivo indefinidamente.
 */
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.$version.'/auth')
    ->name('api.v1.auth.')
    ->group(function (): void {
        Route::post('/refresh', [AuthTokenController::class, 'refresh'])
            ->middleware('throttle:api-auth')
            ->name('refresh');

        Route::post('/logout', [AuthTokenController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthTokenController::class, 'logoutAll'])->name('logout-all');

        /*
         * Mismo handler que `GET /api/v1/user`, con dos nombres.
         *
         * `api.v1.user.me` es el que existe desde la v2.2.0 y no se rompe; el
         * alias bajo `auth/` es el que espera encontrar quien acaba de llamar a
         * `auth/login` y `auth/logout` y busca el tercero de la familia. Cuesta
         * una línea y ahorra un «¿dónde está el me?» por cada cliente nuevo.
         */
        Route::get('/me', [UserController::class, 'me'])->name('me');
    });

/*
 * El resto del módulo.
 */
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.$version)
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/user', [UserController::class, 'me'])->name('user.me');
    });
