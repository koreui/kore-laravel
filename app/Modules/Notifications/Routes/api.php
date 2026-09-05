<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Api\V1\NotificationController;
use App\Modules\Notifications\Http\Controllers\Api\V1\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications — API V1 (Sanctum)
|--------------------------------------------------------------------------
|
| Se cargan sólo con los DOS toggles encendidos: `NOTIFICATIONS_ENABLED` (el
| módulo) y `API_ENABLED` (la API). Lo decide NotificationsModuleServiceProvider.
|
| Mismo contrato que el resto de la API (R54): prefijo `api/v1`, nombres
| `api.v1.*`, controllers que extienden `ApiController` y errores por
| `ApiExceptionRenderer`. Ver docs/guides/api.md.
|
| Todo cuelga de `me` y **sin `abilities:`**, a diferencia de `api/v1/users`:
| una notificación es de quien la recibe, no de quien tiene un permiso. El
| ámbito lo pone la relación del usuario del token y la Policy comprueba la fila.
|
| `read-all` va ANTES de `{notification}/read` porque `read-all` encajaría en el
| parámetro si el router lo mirara primero.
|
*/

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.config('kore-api.version', 'v1').'/me')
    ->name('api.v1.me.')
    ->group(function (): void {
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');

        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.read-all');

        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.read');

        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index'])
            ->name('notification-preferences.index');

        Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update'])
            ->name('notification-preferences.update');
    });
