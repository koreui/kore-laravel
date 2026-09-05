<?php

declare(strict_types=1);

use App\Core\Contracts\InstallationFeatures;
use App\Core\Contracts\NumberSeries;
use App\Core\Contracts\Settings;
use App\Modules\Platform\Support\ConfigFeatures;
use App\Modules\Platform\Support\DatabaseNumberSeries;
use App\Modules\Platform\Support\DatabaseSettings;
use App\Modules\Platform\Support\EditableSettings;

/*
|--------------------------------------------------------------------------
| config/kore-settings.php · config/kore-numbering.php · config/features.php
|--------------------------------------------------------------------------
|
| Ninguno de los tres es `config/kore-app.php`: no declaran capacidades del
| boilerplate sino parámetros, así que el check R11 no los mira. Este test es su
| equivalente, y vigila sobre todo la forma de la que dependen el formulario, la
| Action y el emisor de folios.
|
*/

it('Platform no tiene toggle en kore-app', function (): void {
    // Es la diferencia con Devices, Pdf y Files, y está escrita aquí para que
    // añadir uno sin querer falle en vez de pasar desapercibido.
    expect(config('kore-app'))->not->toHaveKey('platform');
});

it('bindea los tres contratos, siempre', function (): void {
    expect(resolve(Settings::class))->toBeInstanceOf(DatabaseSettings::class)
        ->and(resolve(NumberSeries::class))->toBeInstanceOf(DatabaseNumberSeries::class)
        ->and(resolve(InstallationFeatures::class))->toBeInstanceOf(ConfigFeatures::class);
});

it('los tres contratos son singletons', function (): void {
    // DatabaseSettings memoiza el mapa durante la petición: con un binding
    // transitorio, el layout, un correo y un PDF harían tres lecturas.
    expect(resolve(Settings::class))->toBe(resolve(Settings::class))
        ->and(resolve(NumberSeries::class))->toBe(resolve(NumberSeries::class))
        ->and(resolve(InstallationFeatures::class))->toBe(resolve(InstallationFeatures::class));
});

it('toda clave editable tiene un valor por defecto', function (): void {
    // Si no, la pantalla ofrecería cambiar un ajuste que en una instalación
    // recién clonada no vale nada y nadie sabría cuál era su valor original.
    $defaults = array_keys((array) config('kore-settings.defaults'));

    foreach (array_keys((array) config('kore-settings.editable')) as $key) {
        expect($defaults)->toContain($key);
    }
});

it('los tipos declarados son de los que el formulario sabe pintar', function (): void {
    $editable = resolve(EditableSettings::class)->all();

    expect($editable)->not->toBeEmpty();

    foreach ($editable as $definition) {
        expect($definition['type'])->toBeIn(['string', 'text', 'email', 'bool', 'int'])
            ->and($definition['label'])->not->toBeEmpty();
    }
});

it('ninguna clave editable colisiona con otra al convertirse en slug', function (): void {
    // `organization.name` y `organization_name` producirían el mismo campo de
    // formulario y uno se comería al otro en silencio.
    expect(fn (): array => resolve(EditableSettings::class)->bySlug())->not->toThrow(Throwable::class);
});

it('rechaza un tipo que el formulario no sabe pintar', function (): void {
    config()->set('kore-settings.editable', [
        'organization.name' => ['type' => 'color', 'label' => 'Color'],
    ]);

    expect(fn (): array => resolve(EditableSettings::class)->all())
        ->toThrow(InvalidArgumentException::class);
});

it('el nombre de la organización es obligatorio', function (): void {
    // Es el único ajuste que la aplicación pinta siempre (el layout): dejarlo
    // vaciar sería dejar el producto sin nombre en todas las pantallas.
    expect(config('kore-settings.editable')['organization.name']['required'])->toBeTrue();
});

it('el TTL de la caché de ajustes no es negativo', function (): void {
    expect((int) config('kore-settings.cache_ttl'))->toBeGreaterThanOrEqual(0);
});

it('toda serie declarada usa una política de reinicio conocida', function (): void {
    $series = (array) config('kore-numbering.series');

    expect(config('kore-numbering.defaults.reset'))->toBeIn(['never', 'yearly']);

    foreach ($series as $definition) {
        expect($definition['reset'] ?? 'never')->toBeIn(['never', 'yearly']);
    }
});

it('el formato por defecto lleva la marca del número', function (): void {
    // Sin `{NUMBER}` todos los folios de la serie saldrían con el mismo texto.
    expect((string) config('kore-numbering.defaults.format'))->toContain('{NUMBER');
});

it('features declara un mapa plano de booleanos', function (): void {
    foreach ((array) config('features') as $key => $value) {
        expect($key)->toBeString()
            ->and($value)->toBeBool();
    }
});

it('los webhooks de la API vienen apagados', function (): void {
    // Es el ejemplo de por qué esta capa existe: entregar webhooks obliga a
    // mantener reintentos, firmas y un buzón de fallos.
    expect(config('features.api_webhooks'))->toBeFalse();
});
