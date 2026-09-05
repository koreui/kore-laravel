<?php

declare(strict_types=1);

use App\Modules\Mx\Http\Livewire\PostalCodeField;
use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| <livewire:mx.postal-code-field />
|--------------------------------------------------------------------------
|
| El componente no tiene ruta ni pantalla: se embebe en el formulario de
| dirección de quien lo necesite y publica lo que averigua con un evento. Por
| eso se prueba con `Livewire::test(PostalCodeField::class)` —la clase, no el
| alias— y no hace falta encender el toggle: registrar el alias es asunto del
| provider, y eso lo cubre `MxToggleTest`.
|
| Tampoco hay spec E2E ni fila en el mapa de acceso (R52): no hay ninguna ruta
| GET con nombre que abrir.
|
*/

beforeEach(function (): void {
    State::factory()->create(['code' => '09', 'name' => 'Ciudad de México', 'abbreviation' => 'CDMX']);

    PostalCode::factory()->create([
        'postal_code' => '01000',
        'settlement' => 'San Ángel',
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
});

it('arranca vacío y sin desplegable de colonias', function (): void {
    Livewire::test(PostalCodeField::class)
        ->assertOk()
        ->assertSet('settlementOptions', [])
        ->assertSet('notFound', false);
});

it('resuelve el código postal y avisa al formulario padre', function (): void {
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '01000')
        ->call('lookup')
        ->assertSet('municipality', 'Álvaro Obregón')
        ->assertSet('stateCode', '09')
        ->assertSet('stateName', 'Ciudad de México')
        ->assertSet('city', 'Ciudad de México')
        ->assertSet('notFound', false)
        ->assertSet('settlementOptions', [
            ['value' => 'Axotla', 'label' => 'Axotla (Pueblo)'],
            ['value' => 'San Ángel', 'label' => 'San Ángel (Colonia)'],
        ])
        ->assertDispatched('mx-postal-code-resolved');
});

it('pinta las colonias en el desplegable', function (): void {
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '01000')
        ->call('lookup')
        ->assertSee('San Ángel (Colonia)')
        ->assertSee('Álvaro Obregón');
});

it('deja puesta la colonia cuando el código postal sólo tiene una', function (): void {
    PostalCode::factory()->create([
        'postal_code' => '44100',
        'settlement' => 'Americana',
        'settlement_type' => 'Colonia',
        'state_code' => '09',
    ]);

    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '44100')
        ->call('lookup')
        ->assertSet('settlement', 'Americana');
});

it('avisa cuando los cinco dígitos no están en el catálogo', function (): void {
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '99999')
        ->call('lookup')
        ->assertSet('notFound', true)
        ->assertSet('settlementOptions', [])
        ->assertNotDispatched('mx-postal-code-resolved');
});

it('no se queja mientras el usuario todavía está tecleando', function (): void {
    // Con tres dígitos no encontrar nada es lo normal, no un error que enseñar.
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '010')
        ->call('lookup')
        ->assertSet('notFound', false);
});

it('limpia las colonias del código anterior al corregir un dígito', function (): void {
    // Dejarlas visibles un instante es la forma más fácil de que el usuario
    // elija una colonia que no le corresponde.
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '01000')
        ->call('lookup')
        ->assertCount('settlementOptions', 2)
        ->set('postalCode', '01001')
        ->call('lookup')
        ->assertSet('settlementOptions', [])
        ->assertSet('municipality', null)
        ->assertSet('settlement', null);
});

it('avisa de la colonia elegida en un evento aparte', function (): void {
    Livewire::test(PostalCodeField::class)
        ->set('postalCode', '01000')
        ->call('lookup')
        ->set('settlement', 'San Ángel')
        ->call('selectSettlement')
        ->assertDispatched('mx-settlement-selected');
});
