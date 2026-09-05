<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Support;

use App\Core\Contracts\PdfRenderer;
use App\Core\Data\PdfDocumentData;
use App\Core\Data\PdfOptionsData;
use App\Core\Enums\PdfPaperFormat;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * La implementación de {@see PdfRenderer} sobre spatie/laravel-pdf.
 *
 * Es el ÚNICO sitio del proyecto que conoce el paquete. Todo lo demás —los
 * módulos que emiten documentos, el controller de la vista previa— habla con la
 * interfaz de `Core`, así que cambiar de motor (dompdf, Browsershot, un
 * servicio de terceros) es escribir otro renderer y cambiar el binding del
 * provider.
 *
 * Se llama `Gotenberg…` por el driver que usa de fábrica
 * (`config/laravel-pdf.php`), no porque hable con Gotenberg directamente: el
 * paquete es quien monta la petición multipart contra
 * `/forms/chromium/convert/html`.
 *
 * **Sobre `null` en las opciones.** `PdfOptionsData` deja `format` y `margins`
 * en `null` cuando el documento no opina, y es aquí donde eso se resuelve
 * contra `config/kore-pdf.php`: el DTO no puede leer configuración (R8) y el
 * renderer sí, porque vive en el módulo.
 */
final class GotenbergPdfRenderer implements PdfRenderer
{
    public function fromView(string $view, array $data, PdfOptionsData $options): PdfDocumentData
    {
        $margins = $options->margins ?? $this->configuredMargins();

        $builder = Pdf::view($view, $data)
            ->name($options->filename)
            ->format(($options->format ?? $this->configuredFormat())->value)
            ->margins(
                top: $margins['top'],
                right: $margins['right'],
                bottom: $margins['bottom'],
                left: $margins['left'],
                unit: 'mm',
            );

        if ($options->landscape) {
            $builder = $builder->landscape();
        }

        /*
         * `generatePdfContent()` y no `toResponse()`: lo que este contrato
         * devuelve son bytes, y quien decide si se descargan, se guardan o se
         * adjuntan a un correo es quien los pidió. Es además el método que
         * `Pdf::fake()` intercepta, así que un test no necesita Gotenberg
         * levantado.
         */
        return new PdfDocumentData(
            filename: $builder->downloadName,
            contents: $builder->generatePdfContent(),
        );
    }

    /**
     * El papel por defecto, o A4 si la configuración trae un valor que no
     * existe (una errata en el `.env` no debería tumbar la generación).
     */
    private function configuredFormat(): PdfPaperFormat
    {
        return PdfPaperFormat::tryFrom(mb_strtolower(trim((string) config('kore-pdf.format', 'a4'))))
            ?? PdfPaperFormat::A4;
    }

    /**
     * Los márgenes por defecto, en milímetros.
     *
     * Se completa cada lado por separado: media configuración de márgenes es
     * un caso real (alguien copia el bloque a medias) y un lado ausente valdría
     * `0`, que en Gotenberg es contenido pegado al filo del papel.
     *
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function configuredMargins(): array
    {
        /** @var array<string, mixed> $margins */
        $margins = (array) config('kore-pdf.margins', []);

        $defaults = ['top' => 18.0, 'right' => 16.0, 'bottom' => 26.0, 'left' => 16.0];

        return [
            'top' => (float) ($margins['top'] ?? $defaults['top']),
            'right' => (float) ($margins['right'] ?? $defaults['right']),
            'bottom' => (float) ($margins['bottom'] ?? $defaults['bottom']),
            'left' => (float) ($margins['left'] ?? $defaults['left']),
        ];
    }
}
