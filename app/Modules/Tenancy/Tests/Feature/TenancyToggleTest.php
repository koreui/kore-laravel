<?php

declare(strict_types=1);

use App\Modules\Tenancy\Providers\TenancyModuleServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Stancl\Tenancy\TenancyServiceProvider;

it('does not register stancl tenancy provider when TENANCY_ENABLED is false', function (): void {
    Config::set('kore-app.tenancy.enabled', false);

    $providers = array_keys(app()->getLoadedProviders());

    expect($providers)
        ->not->toContain(TenancyServiceProvider::class)
        ->and(class_exists(TenancyModuleServiceProvider::class))->toBeTrue();
});

it('always exposes the kore:tenancy:enable artisan command', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('kore:tenancy:enable');
});

it('does not register the tenant routes on the central app', function (): void {
    Config::set('kore-app.tenancy.enabled', false);

    // Routes del módulo Tenancy no deben existir cuando el toggle está apagado.
    $routes = collect(resolve('router')->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values()
        ->all();

    expect($routes)->not->toContain('tenant.home');
});
