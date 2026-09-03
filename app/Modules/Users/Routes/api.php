<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Users Module — API routes (Sanctum)
|--------------------------------------------------------------------------
|
| Solo se cargan cuando API_ENABLED=true (ver UsersModuleServiceProvider), igual
| que las de Auth.
|
| CADA ruta lleva su `abilities:` además del `auth:sanctum` del grupo, y el
| controller vuelve a preguntarle a la Policy. Son dos barreras que responden a
| preguntas distintas (R23 · R25):
|
|   - la ability dice qué se le concedió a ESTE TOKEN cuando se emitió, y es lo
|     que hace que un token robado de una app de sólo lectura no pueda borrar
|     nada aunque su dueño sea administrador;
|   - la Policy dice qué puede ESTE USUARIO ahora mismo sobre ESTE registro, y
|     es la única que sabe que sólo un superadmin edita a otro superadmin.
|
| Las abilities de un token son los permisos efectivos de su dueño en el momento
| del login (ver AuthApiTokenIssueAction), así que `abilities:users.edit` se lee
| igual que el `permission:users.edit` de la ruta web equivalente.
|
*/

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.config('kore-api.version', 'v1'))
    ->name('api.v1.users.')
    ->controller(UserController::class)
    ->group(function (): void {
        Route::middleware('abilities:users.view')->get('/users', 'index')->name('index');
        Route::middleware('abilities:users.view')->get('/users/{user}', 'show')->name('show');
        Route::middleware('abilities:users.create')->post('/users', 'store')->name('store');
        Route::middleware('abilities:users.edit')->match(['put', 'patch'], '/users/{user}', 'update')->name('update');
        Route::middleware('abilities:users.delete')->delete('/users/{user}', 'destroy')->name('destroy');
    });
