<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Las dos pantallas del módulo. Sin lógica: montan el componente Livewire que
 * hace el trabajo (R4).
 */
final class NotificationsController extends Controller
{
    public function index(): View
    {
        return view('notifications::pages.index');
    }

    public function preferences(): View
    {
        return view('notifications::pages.preferences');
    }
}
