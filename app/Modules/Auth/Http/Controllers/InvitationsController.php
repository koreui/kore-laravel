<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Las dos pantallas de invitaciones. No hacen nada más que devolver la vista
 * que monta el componente Livewire: el permiso lo comprueba el middleware de la
 * ruta, y otra vez el componente (R23), porque `/livewire/update` no pasa por
 * aquí.
 */
final class InvitationsController extends Controller
{
    public function index(): View
    {
        return view('auth::pages.invitations.index');
    }

    public function create(): View
    {
        return view('auth::pages.invitations.create');
    }
}
