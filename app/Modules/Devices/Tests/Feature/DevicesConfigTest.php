<?php

declare(strict_types=1);

use App\Modules\Devices\Enums\Platform;

/*
|--------------------------------------------------------------------------
| config/devices.php
|--------------------------------------------------------------------------
|
| `devices.platforms` es una lista blanca que un derivado puede recortar
| (`['ios', 'android']` si sólo tiene app móvil). Lo que no puede es ampliarla:
| un valor que no sea un case de `Platform` no se guardaría nunca —el listener
| lo descarta— y quien lo escribiera creería que sí.
|
| `config/devices.php` NO es `config/kore-app.php`: no declara capacidades sino
| parámetros, así que el check R11 no lo mira. Este test es su equivalente.
|
*/

it('sólo admite plataformas que existen en el enum', function (): void {
    $platforms = (array) config('devices.platforms');
    $known = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());

    expect(array_diff($platforms, $known))->toBe([]);
});

it('no se carga con los dos plazos al revés', function (): void {
    // Primero se revoca por silencio y sólo después corre la retención: un
    // `stale_after_days` menor que `prune_after_days` no rompe nada, pero
    // significa que un dispositivo se purgaría antes de haber sido revocado por
    // abandono, y eso casi siempre es un despiste al editar el config.
    expect((int) config('devices.stale_after_days'))
        ->toBeGreaterThan((int) config('devices.prune_after_days'));
});

it('deja pasar cualquier versión de cliente por defecto', function (): void {
    // El corte se sube el día que una versión deja de ser compatible, no antes.
    expect(config('devices.min_app_version'))->toBe('0.0.0');
});
