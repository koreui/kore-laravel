<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications — rutas web
|--------------------------------------------------------------------------
|
| Sólo se cargan con `NOTIFICATIONS_ENABLED=true` (lo decide
| NotificationsModuleServiceProvider). Con el toggle apagado, `/notifications`
| es un 404 como cualquier URL inventada.
|
| `auth` + `verified` y **sin `permission:`**, a diferencia de `/users`: una
| bandeja no es una sección a la que se dé acceso, es algo que todo el mundo
| tiene. Quién puede tocar QUÉ notificación lo decide
| `NotificationPolicy`, dentro de cada componente (R23 · R25).
|
| Las dos rutas están en `tests/e2e/fixtures/access-map.ts` (R52).
|
*/

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('notifications')
    ->as('notifications.')
    ->controller(NotificationsController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/preferences', 'preferences')->name('preferences');
    });
