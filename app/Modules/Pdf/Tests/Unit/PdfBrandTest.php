<?php

declare(strict_types=1);

use App\Core\Data\PdfBrandData;
use App\Modules\Pdf\Support\PdfBrand;

/*
|--------------------------------------------------------------------------
| PdfBrand · la marca por defecto desde config/kore-pdf.php
|--------------------------------------------------------------------------
|
| Estas pruebas no necesitan el toggle encendido: `PdfBrand` y `PdfLogo` son
| clases sueltas que leen configuración, no piezas que registre el provider.
| Lo que se comprueba es lo que la configuración promete —logo embebido, pie
| limpio, marca de agua a petición— y los tres casos en los que devuelve null
| en vez de romper la hoja.
|
*/

/**
 * Escribe un PNG de mentira en `public/` y devuelve su ruta relativa.
 *
 * En `public/` y no en un temporal porque `kore-pdf.logo` es, por contrato, una
 * ruta relativa a `public/`: probar con una absoluta probaría otra cosa.
 */
function pdfBrandLogoFixture(): string
{
    $relative = 'kore-pdf-test-'.bin2hex(random_bytes(6)).'.png';

    file_put_contents(public_path($relative), 'png-de-mentira');

    return $relative;
}

it('embeds the configured logo instead of linking it', function (): void {
    $relative = pdfBrandLogoFixture();

    try {
        config()->set('kore-pdf.logo', $relative);

        $brand = PdfBrand::default();

        expect($brand)->toBeInstanceOf(PdfBrandData::class)
            ->and($brand->logoUrl)->toBe('data:image/png;base64,'.base64_encode('png-de-mentira'))
            // Lo importante no es el base64: es que no hay una URL que Gotenberg
            // tenga que ir a buscar.
            ->and($brand->logoUrl)->not->toContain('http');
    } finally {
        @unlink(public_path($relative));
    }
});

it('takes the second logo from its own key', function (): void {
    $relative = pdfBrandLogoFixture();

    try {
        config()->set('kore-pdf.secondary_logo', $relative);

        expect(PdfBrand::default()->secondaryLogoUrl)->toStartWith('data:image/png;base64,')
            ->and(PdfBrand::default()->logoUrl)->toBeNull();
    } finally {
        @unlink(public_path($relative));
    }
});

it('leaves the header empty when there is no logo configured or the file is gone', function (): void {
    config()->set('kore-pdf.logo');

    expect(PdfBrand::default()->logoUrl)->toBeNull();

    // Configurado pero borrado del disco: el caso real de un despliegue a
    // medias. Sigue siendo null, nunca una imagen rota.
    config()->set('kore-pdf.logo', 'img/no-desplegado.png');

    expect(PdfBrand::default()->logoUrl)->toBeNull();
});

it('accepts the relative path with or without a leading slash', function (): void {
    $relative = pdfBrandLogoFixture();

    try {
        config()->set('kore-pdf.logo', '/'.$relative);

        expect(PdfBrand::default()->logoUrl)->toStartWith('data:image/png;base64,');
    } finally {
        @unlink(public_path($relative));
    }
});

it('drops the empty footer lines and reports whether there is a footer at all', function (): void {
    config()->set('kore-pdf.footer_lines', ['  Razón Social S.A.  ', '', '   ', 'RFC XAXX010101000']);

    $brand = PdfBrand::default();

    expect($brand->footerLines)->toBe(['Razón Social S.A.', 'RFC XAXX010101000'])
        ->and($brand->hasFooter())->toBeTrue();

    config()->set('kore-pdf.footer_lines', []);

    expect(PdfBrand::default()->footerLines)->toBe([])
        ->and(PdfBrand::default()->hasFooter())->toBeFalse();
});

/*
 * La marca de agua se PIDE. Tenerla configurada no la pone: el mismo documento
 * se descarga limpio para entregarlo y sellado para que circule internamente.
 */
it('only stamps the watermark when the caller asks for it', function (): void {
    config()->set('kore-pdf.watermark', 'COPIA NO CONTROLADA');

    expect(PdfBrand::default()->watermark)->toBeNull()
        ->and(PdfBrand::default(withWatermark: true)->watermark)->toBe('COPIA NO CONTROLADA');
});

it('does not stamp an empty watermark even when asked', function (): void {
    config()->set('kore-pdf.watermark', '   ');

    expect(PdfBrand::default(withWatermark: true)->watermark)->toBeNull();
});

it('carries the document code the caller passes, because it belongs to the document', function (): void {
    expect(PdfBrand::default('KORE-PDF-001')->documentCode)->toBe('KORE-PDF-001')
        ->and(PdfBrand::default()->documentCode)->toBeNull();
});
