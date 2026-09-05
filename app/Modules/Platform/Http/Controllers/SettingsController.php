<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * La pantalla de ajustes. Como `UsersController`: sólo devuelve la vista que
 * monta el componente Livewire, porque la lógica vive en el componente y la
 * escritura en la Action (R4).
 */
final class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('platform::pages.settings');
    }
}
