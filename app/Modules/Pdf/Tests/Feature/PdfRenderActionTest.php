<?php

declare(strict_types=1);

use App\Core\Contracts\PdfRenderer;
use App\Core\Data\PdfBrandData;
use App\Core\Data\PdfDocumentData;
use App\Core\Data\PdfOptionsData;
use App\Core\Enums\PdfPaperFormat;
use App\Modules\Pdf\Actions\PdfRenderAction;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Facades\Pdf;

/*
|--------------------------------------------------------------------------
| PdfRenderAction + GotenbergPdfRenderer
|--------------------------------------------------------------------------
|
| El caso de uso «generar un PDF a partir de una hoja» y la única clase del
| proyecto que conoce spatie/laravel-pdf. Se prueban juntos porque lo que hay
| que dejar fijado es el contrato entre los dos: qué llega a la vista y qué
| llega al builder.
|
| `Pdf::fake()` sustituye al builder real, así que nada de esto necesita un
| Gotenberg levantado. Lo que se comprueba es la traducción de
| `PdfOptionsData` a las llamadas del paquete, que es justo lo que se rompería
| en silencio al actualizarlo.
|
| El módulo va apagado en la suite, así que todo pasa por `withEnvironment()`
| — sin toggle no hay binding de `PdfRenderer`, que es lo que prueba
| `PdfToggleTest`.
|
*/

/** Arranca la aplicación con el módulo Pdf encendido. */
function withPdfModule(Closure $callback): void
{
    withEnvironment(['PDF_ENABLED' => 'true'], $callback);
}

it('puts the brand in the view and turns off the browser chrome', function (): void {
    withPdfModule(function (): void {
        $fake = Pdf::fake();

        $brand = new PdfBrandData(documentCode: 'FO-500-REV1', watermark: 'BORRADOR');

        $document = resolve(PdfRenderAction::class)->handle(
            view: 'pdf::examples.sample',
            data: ['title' => 'Factura', 'fields' => [], 'rows' => []],
            brand: $brand,
            options: new PdfOptionsData(filename: 'factura-001'),
        );

        expect($document)->toBeInstanceOf(PdfDocumentData::class)
            ->and($document->size())->toBeGreaterThan(0)
            // `name()` le añade la extensión: quien llama no tiene que acordarse.
            ->and($document->filename)->toBe('factura-001.pdf');

        $fake->assertViewIs('pdf::examples.sample');
        $fake->assertViewHas('brand', $brand);
        // La Action lo fija, no lo hereda: lo que se está generando es el PDF.
        $fake->assertViewHas('paged', false);
        // Y el cromo llega de verdad a la hoja, no sólo al array de datos.
        $fake->assertSee('BORRADOR');
        $fake->assertSee('FO-500-REV1');
    });
});

it('falls back to the configured paper and margins when the document does not care', function (): void {
    withPdfModule(function (): void {
        $fake = Pdf::fake();

        config()->set('kore-pdf.format', 'letter');
        config()->set('kore-pdf.margins', ['top' => 20.0, 'right' => 15.0, 'bottom' => 30.0, 'left' => 15.0]);

        resolve(PdfRenderer::class)->fromView(
            'pdf::layouts.base',
            ['title' => 'Vacío'],
            new PdfOptionsData(filename: 'vacio'),
        );

        expect($fake->format)->toBe('letter')
            ->and($fake->margins)->toBe([
                'top' => 20.0,
                'right' => 15.0,
                'bottom' => 30.0,
                'left' => 15.0,
                // Milímetros SIEMPRE: es la unidad de `config/kore-pdf.php` y la
                // que entiende el `@page` del tema base.
                'unit' => 'mm',
            ])
            ->and($fake->orientation)->toBeNull();
    });
});

it('lets a document override the paper and the orientation', function (): void {
    withPdfModule(function (): void {
        $fake = Pdf::fake();

        resolve(PdfRenderer::class)->fromView(
            'pdf::layouts.base',
            ['title' => 'Anexo'],
            new PdfOptionsData(
                filename: 'anexo',
                format: PdfPaperFormat::Legal,
                landscape: true,
                margins: ['top' => 5.0, 'right' => 5.0, 'bottom' => 5.0, 'left' => 5.0],
            ),
        );

        expect($fake->format)->toBe('legal')
            // El valor lo fija `Spatie\LaravelPdf\Enums\Orientation`, con
            // mayúscula: se compara contra el enum y no contra un literal para
            // que un cambio del paquete se vea aquí y no en un PDF vertical.
            ->and($fake->orientation)->toBe(Orientation::Landscape->value)
            ->and($fake->margins['top'])->toBe(5.0);
    });
});

/*
 * Una errata en el `.env` no debería tumbar la generación de un documento: se
 * cae al A4 de siempre, que es lo que casi todo el mundo imprime.
 */
it('ignores a paper size that does not exist', function (): void {
    withPdfModule(function (): void {
        $fake = Pdf::fake();

        config()->set('kore-pdf.format', 'A-4');

        resolve(PdfRenderer::class)->fromView('pdf::layouts.base', ['title' => 'X'], new PdfOptionsData(filename: 'x'));

        expect($fake->format)->toBe('a4');
    });
});
