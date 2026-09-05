<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Http\Controllers;

use App\Core\Data\PdfOptionsData;
use App\Modules\Pdf\Actions\PdfRenderAction;
use App\Modules\Pdf\Support\PdfSample;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * La vista previa del tema base de PDF.
 *
 * Dos rutas sobre la MISMA hoja (`pdf::examples.sample`): una la sirve como
 * HTML para revisar la maqueta al instante, y la otra la convierte. Que sean la
 * misma vista es el punto: lo que se ve en pantalla es lo que sale impreso, y
 * en cuanto se separen deja de serlo.
 *
 * No es una pantalla de la aplicación sino una herramienta de quien maqueta, y
 * por eso va detrás del gate `viewPdfPreview` (`PdfModuleServiceProvider`) además
 * del toggle `PDF_ENABLED`.
 *
 * R4: aquí no hay lógica. Los datos del ejemplo los pone `PdfSample`, la marca
 * `PdfBrand` y el PDF la Action; el controller sólo elige salida.
 */
final class PdfPreviewController extends Controller
{
    public function show(Request $request): View
    {
        return view('pdf::examples.sample', [
            ...PdfSample::sheet($this->wantsWatermark($request)),
            // En el navegador se pinta el papel simulado; en el PDF pagina
            // Chromium. Es la única diferencia entre las dos salidas.
            'paged' => true,
        ]);
    }

    public function download(Request $request, PdfRenderAction $render): Response
    {
        $sheet = PdfSample::sheet($this->wantsWatermark($request));

        $document = $render->handle(
            view: 'pdf::examples.sample',
            data: $sheet,
            brand: $sheet['brand'],
            options: new PdfOptionsData(filename: 'kore-pdf-ejemplo.pdf'),
        );

        /*
         * `inline` y no `attachment`: la vista previa se abre en el visor del
         * navegador, que es donde se compara con la pantalla de al lado. El
         * `filename` sigue puesto para cuando se guarde desde ahí.
         *
         * La `Response` la monta el controller y no el DTO: `App\Core\Data` no
         * conoce `Illuminate\Http` (R8).
         */
        return response($document->contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
        ]);
    }

    /**
     * Si esta copia va sellada.
     *
     * Va como parámetro de la petición y no como ajuste guardado porque no es
     * una propiedad del documento: es de la copia que se está bajando. El mismo
     * documento se descarga limpio para entregarlo y sellado para que circule
     * internamente, y ninguna de las dos es «la buena».
     */
    private function wantsWatermark(Request $request): bool
    {
        return $request->boolean('watermark');
    }
}
