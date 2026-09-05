<?php

declare(strict_types=1);

use App\Core\Support\PdfImage;

/*
|--------------------------------------------------------------------------
| App\Core\Support\PdfImage
|--------------------------------------------------------------------------
|
| La pieza que decide que las imágenes de una hoja PDF viajen embebidas y no
| enlazadas. Lo que se prueba aquí es sobre todo lo que NO hace: no lanza
| cuando el archivo falta, porque el fallo que evita es precisamente uno
| silencioso (Gotenberg pidiéndose la imagen a sí mismo y dibujando el icono de
| imagen rota dentro del PDF que se le entrega al cliente).
|
| Ver `docs/modules/pdf.md`.
|
*/

/**
 * Un archivo temporal con el contenido y la extensión que se pidan.
 */
function pdfImageFixture(string $extension, string $contents = 'binario'): string
{
    $path = sys_get_temp_dir().'/kore-pdf-image-'.bin2hex(random_bytes(6)).'.'.$extension;

    file_put_contents($path, $contents);

    return $path;
}

it('embeds a file as a data uri with its mime type', function (): void {
    $path = pdfImageFixture('png', 'contenido-png');

    try {
        expect(PdfImage::embedded($path))
            ->toBe('data:image/png;base64,'.base64_encode('contenido-png'));
    } finally {
        @unlink($path);
    }
});

it('maps the extensions a document sheet actually uses', function (string $extension, string $mime): void {
    $path = pdfImageFixture($extension);

    try {
        expect(PdfImage::embedded($path))->toStartWith('data:'.$mime.';base64,');
    } finally {
        @unlink($path);
    }
})->with([
    ['png', 'image/png'],
    ['jpg', 'image/jpeg'],
    ['jpeg', 'image/jpeg'],
    ['svg', 'image/svg+xml'],
    ['webp', 'image/webp'],
    ['gif', 'image/gif'],
    ['avif', 'image/avif'],
    // Lo que no se reconoce no se inventa: el visor decidirá qué hacer.
    ['bin', 'application/octet-stream'],
]);

it('does not care about the case of the extension', function (): void {
    $path = pdfImageFixture('PNG');

    try {
        expect(PdfImage::embedded($path))->toStartWith('data:image/png;base64,');
    } finally {
        @unlink($path);
    }
});

/*
 * El caso que da nombre a la clase: una ruta que no existe devuelve null, y la
 * hoja pinta el hueco. Un PDF sin logo es recuperable; una excepción a mitad de
 * la generación, o un icono de imagen rota delante del cliente, no.
 */
it('returns null instead of throwing when there is no file to read', function (): void {
    expect(PdfImage::embedded(null))->toBeNull()
        ->and(PdfImage::embedded('/no/existe/logo.png'))->toBeNull()
        // Un directorio no es un archivo: `is_file()` lo descarta antes de que
        // `file_get_contents()` emita un warning.
        ->and(PdfImage::embedded(sys_get_temp_dir()))->toBeNull();
});
