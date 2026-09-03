<?php

declare(strict_types=1);

use App\Modules\Docs\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Docs
|--------------------------------------------------------------------------
|
| El archivo sólo lo carga `DocsModuleServiceProvider` cuando
| `DOCS_ENABLED=true`: con el toggle apagado estas rutas no existen y `/docs`
| es un 404 como cualquier otra ruta inexistente.
|
| Sin `auth`: los mismos documentos están publicados en GitHub, así que pedir
| sesión daría una falsa sensación de privacidad. Quien decide si se sirven es
| el toggle, y por eso su default es `false` y producción lo deja apagado.
|
| `where` sin el punto: sin puntos no hay `..` ni `.env` que valgan, y el
| segmento tampoco puede llevar extensión. La comprobación se repite con
| `realpath` en el controlador.
|
*/

Route::middleware('web')
    ->prefix('docs')
    ->as('docs.')
    ->controller(DocsController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/{path}', 'show')->where('path', '[A-Za-z0-9_\-/]+')->name('show');
    });
