<?php

declare(strict_types=1);

use App\Modules\Mx\Data\PostalCodeData;
use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use App\Modules\Mx\Support\PostalCodes;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| PostalCodes::lookup()
|--------------------------------------------------------------------------
|
| La única lectura del módulo. Se prueba con el toggle apagado —el estado por
| defecto de la suite— a propósito: la clase no lo consulta, porque quien decide
| si existe es el provider al registrarla, no ella al ejecutarse.
|
*/

beforeEach(function (): void {
    State::factory()->create(['code' => '09', 'name' => 'Ciudad de México', 'abbreviation' => 'CDMX']);
});

it('devuelve las colonias del código postal, ordenadas por nombre', function (): void {
    PostalCode::factory()->create([
        'postal_code' => '01000',
        'settlement' => 'San Ángel Inn',
        'settlement_type' => 'Colonia',
        'municipality' => 'Álvaro Obregón',
        'city' => 'Ciudad de México',
        'state_code' => '09',
    ]);

    PostalCode::factory()->create([
        'postal_code' => '01000',
        'settlement' => 'Axotla',
        'settlement_type' => 'Pueblo',
        'municipality' => 'Álvaro Obregón',
        'city' => 'Ciudad de México',
        'state_code' => '09',
    ]);

    $found = (new PostalCodes)->lookup('01000');

    expect($found)->toBeInstanceOf(PostalCodeData::class)
        ->and($found?->postalCode)->toBe('01000')
        ->and($found?->municipality)->toBe('Álvaro Obregón')
        ->and($found?->city)->toBe('Ciudad de México')
        ->and($found?->stateCode)->toBe('09')
        // El nombre sale de mx_states y no del CSV.
        ->and($found?->stateName)->toBe('Ciudad de México')
        ->and($found?->settlements)->toBe([
            ['name' => 'Axotla', 'type' => 'Pueblo'],
            ['name' => 'San Ángel Inn', 'type' => 'Colonia'],
        ]);
});

it('devuelve null cuando el código postal no está en el catálogo', function (): void {
    expect((new PostalCodes)->lookup('99999'))->toBeNull();
});

it('devuelve null sin consultar cuando no son cinco dígitos', function (): void {
    // '1000' no es un código postal al que le falte un cero: es un dato mal
    // copiado, y rellenarlo convertiría el error del cliente en una respuesta
    // plausible.
    expect((new PostalCodes)->lookup('1000'))->toBeNull()
        ->and((new PostalCodes)->lookup('abcde'))->toBeNull()
        ->and((new PostalCodes)->lookup(''))->toBeNull();
});

it('acepta el código postal con espacios alrededor', function (): void {
    PostalCode::factory()->create(['postal_code' => '44100', 'state_code' => '09']);

    expect((new PostalCodes)->lookup('  44100 '))->toBeInstanceOf(PostalCodeData::class);
});

it('guarda el resultado en caché y no vuelve a la base', function (): void {
    PostalCode::factory()->create([
        'postal_code' => '64000',
        'settlement' => 'Centro',
        'settlement_type' => 'Colonia',
        'state_code' => '09',
    ]);

    $postalCodes = new PostalCodes;

    expect($postalCodes->lookup('64000')?->settlements)->toHaveCount(1);

    // Se borra la fila: si la segunda llamada consultara, devolvería null.
    PostalCode::query()->where('postal_code', '64000')->delete();

    expect($postalCodes->lookup('64000')?->settlements)->toHaveCount(1)
        ->and(Cache::get(config('mx.cache.prefix').'64000'))->toBeInstanceOf(PostalCodeData::class);
});

it('guarda también los códigos que no existen', function (): void {
    // Un bot que prueba códigos inventados no puede convertir cada intento en
    // una consulta. El centinela `false` es lo que distingue «no está en el
    // catálogo» de «no está en la caché».
    expect((new PostalCodes)->lookup('88888'))->toBeNull()
        ->and(Cache::get(config('mx.cache.prefix').'88888'))->toBeFalse();

    PostalCode::factory()->create(['postal_code' => '88888', 'state_code' => '09']);

    expect((new PostalCodes)->lookup('88888'))->toBeNull();
});
