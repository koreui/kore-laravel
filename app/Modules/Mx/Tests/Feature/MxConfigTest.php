<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| config/mx.php
|--------------------------------------------------------------------------
|
| `config/mx.php` NO es `config/kore-app.php`: no declara capacidades sino
| parámetros, así que el check R11 no lo mira. Este test es su equivalente.
|
*/

it('guarda el catálogo en caché el tiempo suficiente para que sirva de algo', function (): void {
    // Un TTL de cero convertiría la caché en un `if` que no hace nada, y el
    // catálogo cambia cuatro veces al año: una hora es el mínimo defendible.
    expect((int) config('mx.cache.ttl'))->toBeGreaterThanOrEqual(3600);
});

it('prefija las claves de caché para poder vaciarlas sin tocar el resto', function (): void {
    expect(config('mx.cache.prefix'))->toBeString()->not->toBeEmpty();
});

it('escribe en lotes de un tamaño que un driver acepta', function (): void {
    // El upsert manda `chunk_size * 6` placeholders en una sentencia; SQLite
    // corta en 999 variables por defecto en versiones viejas y MySQL protesta
    // mucho más arriba. Mil filas es el equilibrio documentado.
    expect((int) config('mx.import.chunk_size'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(5000);
});
