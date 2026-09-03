<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Core\Contracts\AuthorizationCatalog as AuthorizationCatalogContract;
use App\Models\User;
use App\Modules\Auth\Console\Commands\RegeneratePermissionsCommand;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Http\Livewire\MagicLink;
use App\Modules\Auth\Http\Livewire\Passkeys;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Policies\PasskeyPolicy;
use App\Modules\Auth\Support\AuthorizationCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkey;
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
        'auth.dashboard' => Dashboard::class,
        'auth.magic-link' => MagicLink::class,
        'auth.passkeys' => Passkeys::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);

        // Auth es el dueño de `Role` y `Module`; los demás módulos consumen el
        // catálogo por el contrato de Core y nunca importan estas clases.
        $this->app->singleton(AuthorizationCatalogContract::class, AuthorizationCatalog::class);
    }

    public function boot(): void
    {
        $this->loadModule();
        $this->registerLivewireComponents();
        $this->configureSanctumStateful();
        $this->configureApiRateLimiting();
        $this->registerSuperadminGate();
        $this->registerObservabilityGates();
        $this->registerPasskeyPolicy();

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

    /**
     * `Laravel\Passkeys\Passkey` es un modelo de vendor: no lo alcanza el
     * auto-descubrimiento de policies (que busca `App\Policies\{Modelo}Policy`),
     * así que se registra a mano.
     *
     * Se registra siempre, también con `AUTH_PASSKEYS=false`: una policy no es
     * una capacidad observable —sin rutas ni pantalla no hay nada que
     * autorizar—, y dejar la regla fuera del alcance del toggle evita que una
     * credencial creada cuando estaba encendido quede sin dueño al apagarlo.
     */
    private function registerPasskeyPolicy(): void
    {
        Gate::policy(Passkey::class, PasskeyPolicy::class);
    }

    private function loadModule(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");

        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        $this->loadMigrationsFrom("{$base}/Database/Migrations");
        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
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

    /**
     * Limiter del grupo `api` (bootstrap/app.php hace `throttleApi()`).
     *
     * Laravel (12 y 13) NO trae un `RateLimiter::for('api')` por defecto; sin él,
     * `throttle:api` degradaría a `maxAttempts = (int) 'api' = 0` y bloquearía
     * todas las peticiones. Por eso se registra siempre, incluso con
     * `API_ENABLED=false`: el toggle sólo decide si se cargan las rutas.
     *
     * Va aquí y no en FortifyServiceProvider porque los limiters de Fortify
     * (`login`, `two-factor`) son de ese paquete; éste es del módulo Auth,
     * que es quien publica las rutas API y engancha Sanctum.
     */
    private function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? (string) $request->ip()));
    }

    /**
     * Paneles de observabilidad: Pulse y el `/health` HTML exponen datos de
     * toda la app (queries, jobs, excepciones, uso de disco), así que sólo
     * entra el superadmin. Se definen aquí, junto al Gate::before del
     * superadmin, para tener toda la autorización global en un solo sitio.
     *
     * Pulse registra su propio `viewPulse` (que sólo permite entorno local)
     * vía `callAfterResolving(Gate::class)`; como los providers de paquete
     * arrancan antes que los de la app, esta definición gana.
     */
    private function registerObservabilityGates(): void
    {
        $onlySuperadmin = fn (User $user): bool => $user->hasRole(Role::SUPERADMIN);

        Gate::define('viewPulse', $onlySuperadmin);
        Gate::define('viewHealth', $onlySuperadmin);
    }
}
