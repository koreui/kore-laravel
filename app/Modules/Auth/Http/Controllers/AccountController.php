<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * La pantalla de espera a la que `EnsureAccountIsActive` manda a quien todavía
 * no está activo.
 *
 * Es una ruta **libre** de ese middleware (si no, se redirigiría a sí misma) y
 * la ve también quien ya está activo: es una página informativa, no un secreto,
 * y darle un 403 a quien llega por un enlace viejo sería peor respuesta que
 * enseñarle que su cuenta está bien.
 */
final class AccountController extends Controller
{
    public function pending(): View
    {
        return view('auth::pages.account-pending');
    }
}
