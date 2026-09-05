<?php

declare(strict_types=1);

use App\Modules\Mx\Console\Commands\SepomexImportCommand;
use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| MX_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra nada
| observable: ni rutas, ni el componente Livewire, ni el comando de importación,
| ni las traducciones.
|
| Las dos excepciones documentadas —el ESQUEMA y el espacio de vistas `mx::`— se
| comprueban también aquí. Un toggle apaga comportamiento, no la forma de la base
| (ver docs/architecture/toggles.md y el docblock del provider).
|
| La suite corre con el toggle apagado (phpunit.xml lo fuerza), así que los casos
| "encendido" arrancan la aplicación de nuevo con `withEnvironment()`
| (tests/Pest.php).
|
*/

/**
 * Arranca la aplicación con el módulo (y la API) encendidos.
 *
 * @param array<string, string> $env variables extra para este arranque
 */
function withMxToggleOn(Closure $callback, array $env = []): void
{
    withEnvironment(['MX_ENABLED' => 'true', 'API_ENABLED' => 'true', ...$env], $callback);
}

/*
|--------------------------------------------------------------------------
| Toggle apagado (estado por defecto de la suite)
|--------------------------------------------------------------------------
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.mx.enabled'))->toBeFalse();
});

it('registers no mx route with the toggle off', function (): void {
    expect(Route::has('api.v1.mx.postal-codes.show'))->toBeFalse()
        ->and(Route::has('api.v1.mx.amount-in-words'))->toBeFalse();

    $this->getJson('/api/v1/mx/postal-codes/01000')->assertNotFound();
});

it('does not register the import command with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('mx:sepomex:import');
});

it('does not register the livewire component with the toggle off', function (): void {
    expect(fn (): mixed => Livewire::test('mx.postal-code-field'))
        ->toThrow(ComponentNotFoundException::class);
});

it('migrates both mx tables even with the toggle off', function (): void {
    // El esquema NO depende del toggle: si dependiera, encender MX_ENABLED en
    // producción exigiría una migración a mano con tráfico encima.
    expect(config('kore-app.mx.enabled'))->toBeFalse()
        ->and(Schema::hasTable('mx_states'))->toBeTrue()
        ->and(Schema::hasColumns('mx_states', ['code', 'name', 'abbreviation']))->toBeTrue()
        ->and(Schema::hasTable('mx_postal_codes'))->toBeTrue()
        ->and(Schema::hasColumns('mx_postal_codes', [
            'postal_code', 'settlement', 'settlement_type', 'municipality', 'city', 'state_code',
        ]))->toBeTrue();
});

it('registers the mx view namespace even with the toggle off', function (): void {
    // Blade resuelve <x-mx::…> al COMPILAR la plantilla que las usa, así que un
    // espacio de vistas dentro del toggle deja un 500 en cualquier pantalla que
    // las mencione (precedente: files::).
    expect(view()->exists('mx::livewire.postal-code-field'))->toBeTrue();
});

it('leaves both catalog tables empty on a clean install', function (): void {
    // El catálogo es un dato de un tercero y no viaja en el repositorio: lo trae
    // `mx:sepomex:import`. Una migración que sembrara filas haría que dos
    // instalaciones del mismo commit tuvieran datos distintos.
    expect(State::query()->count())->toBe(0)
        ->and(PostalCode::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('registers the mx routes with the toggle on', function (): void {
    withMxToggleOn(function (): void {
        expect(config('kore-app.mx.enabled'))->toBeTrue()
            ->and(Route::has('api.v1.mx.postal-codes.show'))->toBeTrue()
            ->and(Route::has('api.v1.mx.amount-in-words'))->toBeTrue()
            ->and(route('api.v1.mx.amount-in-words', absolute: false))->toBe('/api/v1/mx/amount-in-words');
    });
});

it('registers the import command with the toggle on', function (): void {
    withMxToggleOn(function (): void {
        expect(array_keys(Artisan::all()))->toContain('mx:sepomex:import')
            ->and(Artisan::all()['mx:sepomex:import'])->toBeInstanceOf(SepomexImportCommand::class);
    });
});

it('registers the livewire component with the toggle on', function (): void {
    withMxToggleOn(function (): void {
        Livewire::test('mx.postal-code-field')->assertOk();
    });
});

it('keeps the mx routes off when the API is off, toggle or not', function (): void {
    // Dos toggles, dos preguntas distintas: el módulo puede estar encendido y la
    // API apagada (un derivado que sólo quiera el componente y el importe en
    // letra dentro de sus Blade).
    withEnvironment(['MX_ENABLED' => 'true', 'API_ENABLED' => 'false'], function (): void {
        expect(config('kore-app.mx.enabled'))->toBeTrue()
            ->and(config('kore-app.api.enabled'))->toBeFalse()
            ->and(Route::has('api.v1.mx.postal-codes.show'))->toBeFalse()
            // ...pero el resto del módulo sí está: el comando y el componente no
            // son API.
            ->and(array_keys(Artisan::all()))->toContain('mx:sepomex:import');
    });
});
