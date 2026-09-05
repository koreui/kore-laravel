<?php

declare(strict_types=1);

use App\Modules\Mx\Http\Controllers\Api\V1\AmountInWordsController;
use App\Modules\Mx\Http\Controllers\Api\V1\PostalCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mx Module — API routes
|--------------------------------------------------------------------------
|
| Se cargan sólo con los DOS toggles encendidos: `MX_ENABLED` (el módulo) y
| `API_ENABLED` (la API). Lo decide MxModuleServiceProvider.
|
| Mismo contrato que el resto de la API (R54): prefijo `api/v1`, nombres
| `api.v1.*`, controllers que extienden `ApiController` y errores por
| `ApiExceptionRenderer`. Ver docs/guides/api.md y docs/modules/mx.md.
|
| **Sin `auth:sanctum`.** Los dos endpoints son catálogo y función pura: no leen
| nada de nadie. El límite lo pone el `throttle:api` que el grupo `api` ya trae
| puesto (`throttleApi()` en bootstrap/app.php), así que NO se repite aquí:
| escribirlo otra vez aplicaría el limitador dos veces a la misma petición.
|
| `api.cache` sí es por endpoint, que es como está pensado: el catálogo cambia
| cuatro veces al año y el importe en letra es determinista, así que las dos
| respuestas aguantan una hora de ETag en el cliente.
|
| Estas rutas NO entran en tests/e2e/fixtures/access-map.ts (R52): el mapa lo
| alimentan las rutas GET con nombre de `Routes/web.php`, que son las que un
| navegador abre como pantalla. Este módulo no tiene ninguna.
|
*/

Route::middleware(['api', 'api.cache:3600'])
    ->prefix('api/'.config('kore-api.version', 'v1'))
    ->name('api.v1.')
    ->group(function (): void {
        /*
         * `amount-in-words` va ANTES que `postal-codes/{postalCode}`: son
         * prefijos distintos, así que el orden no cambia nada hoy, pero deja el
         * segmento con parámetro el último, que es la costumbre que evita
         * sorpresas cuando alguien añada `/mx/algo` mañana.
         */
        Route::get('/mx/amount-in-words', [AmountInWordsController::class, 'show'])
            ->name('mx.amount-in-words');

        Route::get('/mx/postal-codes/{postalCode}', [PostalCodeController::class, 'show'])
            ->name('mx.postal-codes.show');
    });
