<?php

declare(strict_types=1);

use App\Core\Contracts\InstallationFeatures;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Features por instalación
|--------------------------------------------------------------------------
|
| La capa que responde «tu licencia no incluye esto». Ninguna ruta del
| boilerplate lleva `feature:` puesto —los ajustes son del núcleo y ponerle una
| licencia delante sería vender un producto que no se puede configurar—, así que
| las rutas de prueba las define el propio test.
|
*/

beforeEach(function (): void {
    config()->set('features', ['reports' => true, 'api_webhooks' => false]);

    $this->features = resolve(InstallationFeatures::class);
});

it('un feature encendido deja pasar', function (): void {
    Route::middleware(['web', 'feature:reports'])->get('/_test/reports', fn (): string => 'ok');

    $this->get('/_test/reports')->assertOk()->assertSee('ok');
});

it('un feature apagado corta con 403 y lo dice en español', function (): void {
    Route::middleware(['web', 'feature:api_webhooks'])->get('/_test/webhooks', fn (): string => 'ok');

    $this->get('/_test/webhooks')
        ->assertForbidden()
        ->assertSee('Este módulo no está disponible en esta instalación.');
});

it('un feature que no existe se comporta como apagado', function (): void {
    // Lo que no está licenciado explícitamente, no está licenciado: se prefiere
    // la pantalla que hay que encender a entregar un módulo que nadie pagó.
    Route::middleware(['web', 'feature:inventado'])->get('/_test/inventado', fn (): string => 'ok');

    $this->get('/_test/inventado')->assertForbidden();
});

it('devuelve 403 y no 404, para distinguir «no lo tienes» de «no existe»', function (): void {
    Route::middleware(['web', 'feature:api_webhooks'])->get('/_test/status', fn (): string => 'ok');

    $this->get('/_test/status')->assertStatus(403);
});

it('el contrato responde por clave y en bloque', function (): void {
    expect($this->features->enabled('reports'))->toBeTrue()
        ->and($this->features->enabled('api_webhooks'))->toBeFalse()
        ->and($this->features->enabled('inventado'))->toBeFalse()
        ->and($this->features->all())->toBe(['reports' => true, 'api_webhooks' => false]);
});

it('la directiva @feature pinta sólo lo que la instalación incluye', function (): void {
    // Cada bloque en su línea: Blade compila las directivas personalizadas por
    // línea, y dos `@endfeature` seguidos en la misma dejan el segundo sin
    // compilar.
    $encendido = "@feature('reports')\nSÍ\n@endfeature";
    $apagado = "@feature('api_webhooks')\nNO\n@endfeature";

    expect(trim(Blade::render($encendido)))->toBe('SÍ')
        ->and(trim(Blade::render($apagado)))->toBe('');
});

it('features:list muestra cada feature con su variable de entorno', function (): void {
    $this->artisan('features:list')
        ->expectsOutputToContain('reports')
        ->expectsOutputToContain('FEATURE_API_WEBHOOKS')
        ->assertSuccessful();
});

it('features:list avisa cuando no hay ninguno declarado', function (): void {
    config()->set('features', []);

    $this->artisan('features:list')
        ->expectsOutputToContain('No hay ningún feature declarado')
        ->assertSuccessful();
});
