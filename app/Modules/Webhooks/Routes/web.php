<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\WebhooksController;
use Illuminate\Support\Facades\Route;

/*
 * Las rutas sólo existen con `WEBHOOKS_ENABLED=true`: quien las carga es
 * `WebhooksModuleServiceProvider` después de su early return (R10).
 *
 * `permission:webhooks.manage` en las cuatro, y va DENTRO del mismo array que
 * `web`, `auth` y `verified`. No es estilo: `RouteRegistrar::middleware()`
 * **sustituye** el atributo en vez de acumularlo, así que un segundo
 * `->middleware('permission:…')` encadenado se lleva por delante al grupo `web`
 * entero —y con él `SubstituteBindings`—. El síntoma no es un 403 ni un 404: es
 * que el controller recibe un modelo vacío y la pantalla revienta más abajo,
 * con un error que no habla de rutas.
 *
 * Es la primera puerta y no la única: las llamadas de Livewire viajan por
 * `/livewire/update`, donde este middleware NO corre, así que cada método
 * público que escribe vuelve a autorizar dentro del componente (R23).
 *
 * `{endpoint:uuid}` enruta por la identidad pública y no por el id entero: un
 * `/webhooks/7` diría cuántas integraciones hay en la instalación. `/create` se
 * declara ANTES que `/{endpoint:uuid}` porque Laravel casa por orden y si no la
 * palabra «create» se leería como un uuid.
 */
Route::middleware(['web', 'auth', 'verified', 'permission:webhooks.manage'])
    ->prefix('webhooks')
    ->as('webhooks.')
    ->controller(WebhooksController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::get('/{endpoint:uuid}', 'show')->name('show');
        Route::get('/{endpoint:uuid}/edit', 'edit')->name('edit');
    });
