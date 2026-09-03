<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DOCS_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra ni rutas
| ni traducciones. La prueba no es que la pantalla responda 403, es que la ruta
| **no existe**.
|
| (La única cosa que sí se registra siempre es el namespace de vistas `docs::`,
| y el provider explica por qué: sin rutas no se puede llegar a ellas.)
|
| La suite corre con el toggle apagado (`phpunit.xml` lo fuerza a false para
| que el resultado no dependa del `.env` de cada máquina); los tests que
| necesitan el visor lo encienden con `withEnvironment()` (tests/Pest.php).
|
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.docs.enabled'))->toBeFalse();
});

it('registers no docs route with the toggle off', function (): void {
    expect(Route::has('docs.index'))->toBeFalse()
        ->and(Route::has('docs.show'))->toBeFalse();
});

it('answers 404 on the docs urls with the toggle off', function (): void {
    $this->get('/docs')->assertNotFound();
    $this->get('/docs/architecture/rules')->assertNotFound();
});

it('registers the docs routes with the toggle on', function (): void {
    withEnvironment(['DOCS_ENABLED' => 'true'], function (): void {
        expect(config('kore-app.docs.enabled'))->toBeTrue()
            ->and(Route::has('docs.index'))->toBeTrue()
            ->and(Route::has('docs.show'))->toBeTrue()
            ->and(route('docs.index'))->toEndWith('/docs')
            ->and(route('docs.show', ['path' => 'architecture/rules']))->toEndWith('/docs/architecture/rules');
    });
});

it('does not load the module translations with the toggle off', function (): void {
    // Con el toggle apagado el `en.json` del módulo no se carga; la clave se
    // queda sin traducir, que es la señal de que el provider no registró nada.
    App::setLocale('en');

    expect(__('En esta página'))->toBe('En esta página');

    withEnvironment(['DOCS_ENABLED' => 'true'], function (): void {
        App::setLocale('en');

        expect(__('En esta página'))->toBe('On this page');
    });
});
