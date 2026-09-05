<?php

declare(strict_types=1);

use App\Core\Contracts\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| settings:show
|--------------------------------------------------------------------------
|
| La columna que importa es la tercera: «es Kore porque nadie lo ha cambiado» y
| «es Kore porque alguien lo guardó así» son dos situaciones distintas, y esa
| diferencia es la primera pregunta de cualquier soporte.
|
| Se lee la salida completa con `Artisan::output()` en vez de encadenar
| `expectsOutputToContain`: ése consume una LÍNEA por expectativa, y aquí el
| valor y su origen viven en la misma fila de la tabla.
|
*/

it('marca como config lo que no tiene fila', function (): void {
    Artisan::call('settings:show');

    expect(Artisan::output())
        ->toContain('organization.name')
        ->toContain('config');
});

it('marca como base de datos lo que sí la tiene', function (): void {
    $actor = User::factory()->create();
    resolve(Settings::class)->set('organization.name', 'Notaría 42', $actor->id);

    expect(Artisan::call('settings:show'))->toBe(0);
    expect(Artisan::output())
        ->toContain('Notaría 42')
        ->toContain('base de datos');
});

it('avisa cuando no hay ningún ajuste declarado', function (): void {
    config()->set('kore-settings.defaults', []);
    config()->set('kore-settings.editable', []);

    Artisan::call('settings:show');

    expect(Artisan::output())->toContain('No hay ningún ajuste declarado');
});
