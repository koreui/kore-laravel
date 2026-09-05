<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Las cuatro pantallas del módulo. Sólo devuelven vistas: todo lo interactivo
 * vive en los componentes Livewire, que son los que autorizan cada escritura
 * (R23).
 */
final class WebhooksController extends Controller
{
    public function index(): View
    {
        return view('webhooks::pages.index');
    }

    public function create(): View
    {
        return view('webhooks::pages.create');
    }

    public function show(WebhookEndpoint $endpoint): View
    {
        return view('webhooks::pages.show', ['model' => $endpoint]);
    }

    public function edit(WebhookEndpoint $endpoint): View
    {
        return view('webhooks::pages.edit', ['model' => $endpoint]);
    }
}
