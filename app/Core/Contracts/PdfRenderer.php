<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Data\PdfDocumentData;
use App\Core\Data\PdfOptionsData;

/**
 * Convierte una vista Blade en un PDF.
 *
 * Es la frontera entre el módulo que **sabe generar** PDFs (`App\Modules\Pdf`,
 * con Gotenberg detrás) y los módulos que sólo **quieren uno**. Gracias a este
 * contrato, un módulo de facturas no importa una sola clase de `App\Modules\Pdf`
 * ni conoce spatie/laravel-pdf (R5, R7).
 *
 * La implementación —`App\Modules\Pdf\Support\GotenbergPdfRenderer`— la bindea
 * `PdfModuleServiceProvider::register()`, y **sólo con `PDF_ENABLED=true`**. Un
 * proyecto derivado que no emite documentos no arrastra el binding, y quien
 * resuelva este contrato con el toggle apagado se lleva un
 * `BindingResolutionException` en vez de un PDF vacío: si el módulo está
 * apagado, quien lo llama tiene un error de configuración, no un caso de uso.
 *
 * Ver `docs/modules/pdf.md`.
 */
interface PdfRenderer
{
    /**
     * El PDF de una vista Blade.
     *
     * @param string $view Nombre de la vista, con su namespace (`facturas::pdf.factura`).
     * @param array<string, mixed> $data Datos de la vista. DTOs y arrays, nunca modelos Eloquent (R30).
     * @param PdfOptionsData $options Papel, orientación, márgenes y nombre del archivo.
     */
    public function fromView(string $view, array $data, PdfOptionsData $options): PdfDocumentData;
}
