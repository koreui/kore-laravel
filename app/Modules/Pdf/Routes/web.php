<?php

declare(strict_types=1);

use App\Modules\Pdf\Http\Controllers\PdfPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pdf
|--------------------------------------------------------------------------
|
| El archivo sólo lo carga `PdfModuleServiceProvider` cuando `PDF_ENABLED=true`:
| con el toggle apagado estas rutas no existen y `/pdf/preview` es un 404 como
| cualquier otra ruta inexistente.
|
| Dos candados y no uno. `auth` porque la vista previa enseña el logo, el pie y
| el código de los documentos de la empresa; y `can:viewPdfPreview` porque no es
| una pantalla de la aplicación sino la herramienta de quien maqueta: fuera del
| equipo que la mantiene sólo genera preguntas. El gate lo define el provider
| del módulo, como `viewApiDocs` en `ApiDocsServiceProvider`.
|
| Sin `verified`: quien tiene el permiso ya pasó por un alta manual.
|
*/

Route::middleware(['web', 'auth', 'can:viewPdfPreview'])
    ->prefix('pdf')
    ->as('pdf.')
    ->controller(PdfPreviewController::class)
    ->group(function (): void {
        Route::get('preview', 'show')->name('preview');
        Route::get('preview/download', 'download')->name('preview.download');
    });
