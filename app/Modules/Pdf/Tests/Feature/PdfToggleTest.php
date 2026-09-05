<?php

declare(strict_types=1);

use App\Core\Contracts\PdfRenderer;
use App\Modules\Pdf\Support\GotenbergPdfRenderer;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PDF_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra ni el
| binding del renderer, ni las rutas, ni el gate, ni las traducciones. La
| prueba no es que la pantalla responda 403: es que **no existe**.
|
| (La única cosa que sí se registra siempre es el namespace de vistas `pdf::`,
| y el provider explica por qué: sin rutas no se puede llegar a ellas.)
|
| La suite corre con el toggle apagado (`phpunit.xml` lo fuerza a false para
| que el resultado no dependa del `.env` de cada máquina); los tests que
| necesitan el módulo lo encienden con `withEnvironment()` (tests/Pest.php).
|
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.pdf.enabled'))->toBeFalse();
});

it('registers no pdf route with the toggle off', function (): void {
    expect(Route::has('pdf.preview'))->toBeFalse()
        ->and(Route::has('pdf.preview.download'))->toBeFalse();
});

it('answers 404 on the pdf urls with the toggle off', function (): void {
    $this->get('/pdf/preview')->assertNotFound();
    $this->get('/pdf/preview/download')->assertNotFound();
});

/*
 * El binding es lo que de verdad apaga el módulo. Que desaparezca no es un
 * efecto secundario: un renderer registrado con el módulo apagado haría creer
 * a quien lo resuelve que tiene PDFs, y lo descubriría cuando Gotenberg no
 * respondiera. Sin binding, el error llega antes y con nombre.
 */
it('does not bind the renderer contract with the toggle off', function (): void {
    expect(App::bound(PdfRenderer::class))->toBeFalse();

    expect(fn (): PdfRenderer => App::make(PdfRenderer::class))
        ->toThrow(BindingResolutionException::class);
});

it('does not define the preview gate with the toggle off', function (): void {
    expect(Gate::has('viewPdfPreview'))->toBeFalse();
});

/*
 * La excepción documentada: el namespace de vistas se registra siempre, porque
 * Larastan valida `view('pdf::examples.sample')` contra la aplicación que
 * arranca durante el análisis y en CI el toggle vale su default. Registrar
 * dónde viven las vistas no expone nada: sin rutas no hay forma de llegar.
 */
it('still knows where the pdf views live with the toggle off', function (): void {
    expect(view()->exists('pdf::layouts.base'))->toBeTrue()
        ->and(view()->exists('pdf::examples.sample'))->toBeTrue();
});

it('does not load the module translations with the toggle off', function (): void {
    App::setLocale('en');

    expect(__('Documento de ejemplo'))->toBe('Documento de ejemplo');

    withEnvironment(['PDF_ENABLED' => 'true'], function (): void {
        App::setLocale('en');

        expect(__('Documento de ejemplo'))->toBe('Sample document');
    });
});

it('registers routes, gate and renderer with the toggle on', function (): void {
    withEnvironment(['PDF_ENABLED' => 'true'], function (): void {
        expect(config('kore-app.pdf.enabled'))->toBeTrue()
            ->and(Route::has('pdf.preview'))->toBeTrue()
            ->and(Route::has('pdf.preview.download'))->toBeTrue()
            ->and(route('pdf.preview'))->toEndWith('/pdf/preview')
            ->and(route('pdf.preview.download'))->toEndWith('/pdf/preview/download')
            ->and(Gate::has('viewPdfPreview'))->toBeTrue()
            ->and(App::make(PdfRenderer::class))->toBeInstanceOf(GotenbergPdfRenderer::class);
    });
});

/*
 * El driver que trae el boilerplate. No se prueba que Gotenberg responda —eso
 * es un servicio, no código nuestro—: se prueba que la configuración publicada
 * en `config/laravel-pdf.php` sigue eligiéndolo, que es lo que se rompe sin
 * avisar al actualizar el paquete.
 */
it('ships gotenberg as the configured driver', function (): void {
    expect(config('laravel-pdf.driver'))->toBe('gotenberg')
        ->and(config('laravel-pdf.gotenberg.url'))->toBe('http://127.0.0.1:3000')
        // Publicado a medias: las claves que no están aquí las sigue poniendo
        // el paquete con `mergeConfigFrom()`, que es un merge de primer nivel.
        ->and(config('laravel-pdf.encrypter'))->not->toBeNull()
        ->and(config('laravel-pdf.job'))->not->toBeNull();
});
