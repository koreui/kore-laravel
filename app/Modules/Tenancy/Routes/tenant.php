<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenancy Module — Tenant routes
|--------------------------------------------------------------------------
|
| Estas rutas se cargan SOLO si TENANCY_ENABLED=true (via
| TenancyModuleServiceProvider). Quedan resueltas dentro del contexto del
| tenant inicializado por InitializeTenancyByDomain.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::get('/', fn (): Factory|View => view('welcome'))->name('tenant.home');
});
