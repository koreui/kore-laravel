<?php

declare(strict_types=1);

use App\Modules\Mx\Support\MontoEnLetras;

/*
|--------------------------------------------------------------------------
| MontoEnLetras
|--------------------------------------------------------------------------
|
| Es una función pura, así que el test es una tabla: importe → cadena. El dataset
| cubre los sitios donde el español cambia de forma —el apócope, el «cien» que se
| vuelve «ciento», las decenas de la veintena, el singular de «millón»— y los
| extremos de la escala.
|
| Va en Tests/Unit y no en Feature: no necesita base de datos ni aplicación.
|
*/

it('escribe el importe en letra', function (float $amount, string $expected): void {
    expect((new MontoEnLetras)->format($amount))->toBe($expected);
})->with([
    // Cero y centavos sueltos.
    'cero' => [0.0, 'CERO PESOS 00/100 M.N.'],
    'sólo centavos' => [0.5, 'CERO PESOS 50/100 M.N.'],
    'noventa y nueve centavos' => [0.99, 'CERO PESOS 99/100 M.N.'],

    // Apócope: UN y no UNO.
    'uno' => [1.0, 'UN PESOS 00/100 M.N.'],
    'dos' => [2.0, 'DOS PESOS 00/100 M.N.'],

    // La veintena, que en español va en una sola palabra.
    'quince' => [15.0, 'QUINCE PESOS 00/100 M.N.'],
    'dieciséis' => [16.0, 'DIECISÉIS PESOS 00/100 M.N.'],
    'veinte' => [20.0, 'VEINTE PESOS 00/100 M.N.'],
    'veintiún' => [21.0, 'VEINTIÚN PESOS 00/100 M.N.'],
    'veintidós' => [22.0, 'VEINTIDÓS PESOS 00/100 M.N.'],
    'veintinueve' => [29.0, 'VEINTINUEVE PESOS 00/100 M.N.'],

    // A partir de treinta, decena + Y + unidad.
    'treinta' => [30.0, 'TREINTA PESOS 00/100 M.N.'],
    'treinta y un' => [31.0, 'TREINTA Y UN PESOS 00/100 M.N.'],
    'noventa y nueve' => [99.0, 'NOVENTA Y NUEVE PESOS 00/100 M.N.'],

    // CIEN sólo cuando está solo.
    'cien' => [100.0, 'CIEN PESOS 00/100 M.N.'],
    'ciento un' => [101.0, 'CIENTO UN PESOS 00/100 M.N.'],
    'ciento quince' => [115.0, 'CIENTO QUINCE PESOS 00/100 M.N.'],
    'doscientos' => [200.0, 'DOSCIENTOS PESOS 00/100 M.N.'],
    'quinientos' => [500.0, 'QUINIENTOS PESOS 00/100 M.N.'],
    'novecientos noventa y nueve' => [999.0, 'NOVECIENTOS NOVENTA Y NUEVE PESOS 00/100 M.N.'],

    // Millares: siempre con su cuantificador delante.
    'mil' => [1000.0, 'UN MIL PESOS 00/100 M.N.'],
    'mil uno' => [1001.0, 'UN MIL UN PESOS 00/100 M.N.'],
    'el ejemplo del doc' => [1234.56, 'UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.'],
    'quince mil' => [15000.0, 'QUINCE MIL PESOS 00/100 M.N.'],
    'veintiún mil' => [21000.0, 'VEINTIÚN MIL PESOS 00/100 M.N.'],
    'cien mil' => [100000.0, 'CIEN MIL PESOS 00/100 M.N.'],
    'el mayor de seis cifras' => [999999.0, 'NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE PESOS 00/100 M.N.'],

    // Millones: singular y plural.
    'un millón' => [1000000.0, 'UN MILLÓN PESOS 00/100 M.N.'],
    'un millón uno' => [1000001.0, 'UN MILLÓN UN PESOS 00/100 M.N.'],
    'dos millones' => [2000000.0, 'DOS MILLONES PESOS 00/100 M.N.'],
    'veintiún millones' => [21000000.0, 'VEINTIÚN MILLONES PESOS 00/100 M.N.'],
    'mil millones' => [1000000000.0, 'UN MIL MILLONES PESOS 00/100 M.N.'],
    'el tope de nueve cifras' => [999999999.99, 'NOVECIENTOS NOVENTA Y NUEVE MILLONES NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE PESOS 99/100 M.N.'],
]);

it('redondea a dos decimales antes de escribir', function (): void {
    // 1234.567 no se trunca a 56: se redondea, como el importe de la factura.
    expect((new MontoEnLetras)->format(1234.567))
        ->toBe('UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 57/100 M.N.');
});

it('no pierde el centavo por el error de representación del float', function (): void {
    // Restar la parte entera de 1234.56 da 0.55999999999995: con un truncado,
    // esto diría 55/100. Es la razón de que todo el cálculo vaya en centavos.
    expect((new MontoEnLetras)->format(1234.56))->toContain('56/100');
});

it('deja la moneda y el sufijo en manos de quien llama', function (): void {
    expect((new MontoEnLetras)->format(1.0, 'PESO'))->toBe('UN PESO 00/100 M.N.')
        ->and((new MontoEnLetras)->format(100.0, 'DÓLARES', 'USD'))->toBe('CIEN DÓLARES 00/100 USD');
});

it('omite el sufijo cuando se pasa vacío, sin dejar el espacio', function (): void {
    expect((new MontoEnLetras)->format(100.0, 'PESOS', ''))->toBe('CIEN PESOS 00/100');
});

it('se niega a escribir un importe negativo', function (): void {
    expect(fn (): string => (new MontoEnLetras)->format(-1.0))
        ->toThrow(InvalidArgumentException::class);
});

it('se niega a salirse de la escala que sabe nombrar', function (): void {
    expect(fn (): string => (new MontoEnLetras)->format(1_000_000_000_000.0))
        ->toThrow(InvalidArgumentException::class);
});
