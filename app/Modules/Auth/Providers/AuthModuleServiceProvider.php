<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Http\Livewire\MagicLink;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Livewire\Livewire;

final class AuthModuleServiceProvider extends ServiceProvider
{
    /**
     * Componentes Livewire del módulo. Se registran sólo los que aportan
     * reactividad real; los flujos clásicos de Fortify viven en blades
     * planas que hacen form POST a las rutas que registra Fortify.
     *
     * @var array<string, class-string>
     */
    private const LIVEWIRE_COMPONENTS = [
        'auth.magic-link' => MagicLink::class,
    ];

    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadModule();
        $this->registerLivewireComponents();
        $this->configureSanctumStateful();
    }

    private function loadModule(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");

        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        $this->loadMigrationsFrom("{$base}/Database/Migrations");
        $this->loadViewsFrom("{$base}/Resources/views", 'auth');
        Blade::anonymousComponentPath("{$base}/Resources/views", 'auth');
    }

    private function registerLivewireComponents(): void
    {
        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }
    }

    private function configureSanctumStateful(): void
    {
        if (! (bool) config('kore-app.api.enabled')) {
            return;
        }

        $this->app->make(HttpKernel::class)
            ->prependMiddlewareToGroup('api', EnsureFrontendRequestsAreStateful::class);
    }
}
