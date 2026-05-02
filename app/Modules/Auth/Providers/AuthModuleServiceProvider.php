<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Models\User;
use App\Modules\Auth\Console\Commands\RegeneratePermissionsCommand;
use App\Modules\Auth\Http\Livewire\MagicLink;
use App\Modules\Auth\Models\Role;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Livewire\Livewire;
use Override;

final class AuthModuleServiceProvider extends ServiceProvider
{
    /**
     * Componentes Livewire del módulo. Se registran sólo los que aportan
     * reactividad real; los flujos clásicos de Fortify viven en blades
     * planas que hacen form POST a las rutas que registra Fortify.
     *
     * @var array<string, class-string>
     */
    private const array LIVEWIRE_COMPONENTS = [
        'auth.magic-link' => MagicLink::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadModule();
        $this->registerLivewireComponents();
        $this->configureSanctumStateful();
        $this->registerSuperadminGate();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegeneratePermissionsCommand::class,
            ]);
        }
    }

    /**
     * Bypass total para el rol superadmin. Cualquier @can / authorize() retorna
     * true automáticamente. Se asigna sólo por consola para evitar abuso UI.
     */
    private function registerSuperadminGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if ($user instanceof User && $user->hasRole(Role::SUPERADMIN)) {
                return true;
            }

            return null;
        });
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
