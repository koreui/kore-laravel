<?php

declare(strict_types=1);

use App\Modules\E2E\Http\Controllers\HarnessController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E2E Harness — rutas de servicio de la suite de pruebas
|--------------------------------------------------------------------------
|
| Estas rutas SÓLO existen cuando E2EModuleServiceProvider decide registrarlas,
| y para eso hacen falta los tres candados de App\Modules\E2E\Support\
| HarnessGuard: el flag, el entorno y una base de pruebas.
|
| Van sobre el grupo `web` porque varias necesitan sesión (entrar como un rol),
| pero SIN `auth`: su razón de ser es preparar el terreno antes de que haya
| sesión.
|
| Y sin CSRF. En Laravel 13 el middleware del grupo `web` que lo comprueba es
| `PreventRequestForgery` (`ValidateCsrfToken` es su subclase deprecada, así
| que excluir esa NO quitaría nada: la exclusión casa por clase exacta o por
| subclase, y aquí la que corre es la padre). El cliente del harness es `fetch`
| desde Node, sin cookie de sesión previa ni token que enviar; exigirlo
| significaría pedirle a la suite que primero abra una página del producto sólo
| para robarle el token.
|
| El prefijo `__e2e__` es feo a propósito: nadie lo confunde con una ruta del
| producto, y en un `route:list` salta a la vista.
|
*/

Route::middleware('web')
    ->prefix('__e2e__')
    ->as('e2e.')
    ->controller(HarnessController::class)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->group(function (): void {
        Route::get('/ping', 'ping')->name('ping');

        Route::post('/login-as', 'loginAs')->name('login-as');
        Route::post('/logout', 'logout')->name('logout');

        Route::post('/users', 'createUser')->name('users.store');
        Route::delete('/users', 'deleteUser')->name('users.destroy');

        Route::get('/mail/last', 'lastMail')->name('mail.last');
        Route::delete('/mail', 'clearMail')->name('mail.clear');

        Route::post('/artisan', 'artisan')->name('artisan');

        Route::post('/throttle/clear', 'clearThrottle')->name('throttle.clear');
    });
