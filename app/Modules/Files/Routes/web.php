<?php

declare(strict_types=1);

use App\Modules\Files\Http\Controllers\FileServeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo Files — rutas web
|--------------------------------------------------------------------------
|
| Una sola ruta, y las tres piezas de su middleware cuentan:
|
|   `web`       · sesión y cookies. No hace falta para autorizar —la firma se
|                 basta—, pero sí para que el fichero se comporte como el resto
|                 del sitio: mismas cabeceras de seguridad, misma CSP.
|   `signed`    · la autorización. Ver el docblock de `FileServeController`.
|   `throttle`  · el límite por IP de `files.throttle`, que es lo que impide que
|                 una URL filtrada se use en bucle mientras dure la firma.
|
| **No lleva `auth`**, y es a propósito: quien emite la URL ya autorizó. Poner
| `auth` aquí rompería el `<img src>` de un correo y la descarga desde un
| convertidor externo sin ganar nada.
|
| R52 no la exige en `tests/e2e/fixtures/access-map.ts` porque el mapa es de
| rutas literales sin parámetros, y ésta lleva `{file}`. Su cobertura está en
| `FilesServeTest` (403 sin firma, 200 con ella, URL distinta al cambiar el
| fichero) y, de punta a punta, en `tests/e2e/specs/users/avatar.spec.ts`.
|
*/

Route::middleware(['web', 'signed', 'throttle:'.config('files.throttle', '60,1')])
    ->get('/files/{file}', FileServeController::class)
    ->name('files.serve');
