<?php

declare(strict_types=1);

use App\Modules\Files\Support\SlotPathGenerator;

/*
|--------------------------------------------------------------------------
| config/files.php y config/media-library.php
|--------------------------------------------------------------------------
|
| `config/files.php` NO es `config/kore-app.php`: no declara capacidades sino
| parámetros, así que el check R11 no lo mira. Este test es su equivalente, y
| vigila sobre todo las dos cifras que viven en DOS archivos porque R12 impide
| que uno lea al otro.
|
*/

it('el disco de media-library es el mismo que el del módulo', function (): void {
    // R12 obliga a que `media-library.disk_name` lea `FILES_DISK` de env() en
    // vez de `config('files.disk')`. Duplicar una cifra sale barato mientras
    // alguien vigile que no se separen; esto es ese alguien.
    expect(config('media-library.disk_name'))->toBe(config('files.disk'));
});

it('el límite de subida de la aplicación cabe en el del paquete', function (): void {
    // Si media-library cortara antes que la validación, un archivo admitido por
    // el formulario reventaría al guardarse, y el mensaje hablaría de bytes.
    expect((int) config('media-library.max_file_size'))
        ->toBeGreaterThanOrEqual((int) config('files.max_upload_kb') * 1024);
});

it('usa el generador de rutas del módulo', function (): void {
    expect(config('media-library.path_generator'))->toBe(SlotPathGenerator::class);
});

it('la compresión y la sincronización vienen apagadas', function (): void {
    // Las dos tienen requisitos fuera de PHP (Ghostscript, un bucket). Un
    // boilerplate no puede dar por hecho ninguno de los dos.
    expect(config('files.compression.enabled'))->toBeFalse()
        ->and(config('files.sync.enabled'))->toBeFalse();
});

it('la URL firmada caduca en un plazo razonable', function (): void {
    expect((int) config('files.url_ttl_minutes'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(60 * 24);
});

it('el limitador de la ruta tiene la forma que espera el middleware throttle', function (): void {
    expect((string) config('files.throttle'))->toMatch('/^\d+,\d+$/');
});
