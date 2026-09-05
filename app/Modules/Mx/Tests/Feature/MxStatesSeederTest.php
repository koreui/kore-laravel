<?php

declare(strict_types=1);

use App\Modules\Mx\Database\Seeders\MxStatesSeeder;
use App\Modules\Mx\Models\State;

/*
|--------------------------------------------------------------------------
| MxStatesSeeder
|--------------------------------------------------------------------------
|
| Las 32 entidades son la única parte del catálogo que sí vive en el repositorio.
| El seeder es lo que hace que la FK de mx_postal_codes tenga a dónde apuntar, y
| por eso lo llama `mx:sepomex:import` antes de escribir nada.
|
*/

it('siembra las 32 entidades federativas', function (): void {
    (new MxStatesSeeder)->run();

    expect(State::query()->count())->toBe(32);
});

it('usa la clave SAT de dos caracteres, con su cero delante', function (): void {
    (new MxStatesSeeder)->run();

    expect(State::query()->where('code', '01')->value('name'))->toBe('Aguascalientes')
        ->and(State::query()->where('code', '09')->value('name'))->toBe('Ciudad de México')
        ->and(State::query()->where('code', '32')->value('name'))->toBe('Zacatecas')
        // Guardar la clave como entero publicaría «9» donde el SAT espera «09».
        ->and(State::query()->pluck('code')->every(fn (string $code): bool => mb_strlen($code) === 2))
        ->toBeTrue();
});

it('se puede correr dos veces sin duplicar ni una fila', function (): void {
    (new MxStatesSeeder)->run();
    (new MxStatesSeeder)->run();

    expect(State::query()->count())->toBe(32);
});

it('cabe la abreviatura más larga de las 32', function (): void {
    (new MxStatesSeeder)->run();

    // TAMPS es la que obligó a que la columna fuera de cinco y no de cuatro.
    expect(State::query()->where('code', '28')->value('abbreviation'))->toBe('TAMPS');
});
