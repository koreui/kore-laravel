<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Core\Contracts\AuthorizationCatalog as AuthorizationCatalogContract;
use App\Models\User;
use App\Modules\Auth\Console\Commands\RegeneratePermissionsCommand;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Http\Livewire\DevAccountSwitcher;
use App\Modules\Auth\Http\Livewire\MagicLink;
use App\Modules\Auth\Http\Livewire\Passkeys;
use App\Modules\Auth\Listeners\RevokeApiTokensOnPermissionChange;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Policies\PasskeyPolicy;
use App\Modules\Auth\Support\AuthorizationCatalog;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkey;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Livewire\Livewire;
use Override;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

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
        $this->registerSuperadminGate();
        $this->registerObservabilityGates();
        $this->registerPasskeyPolicy();
        $this->registerApiTokenRevocation();

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

    /**
     * Un cambio de roles o permisos retira los tokens de API del usuario.
     *
     * Se cablea **siempre**, también con `API_ENABLED=false`: el toggle decide
     * si hay rutas de API, no si los tokens que ya existen en la tabla siguen
     * abriendo puertas. Un despliegue que apaga la API y deja vivos los tokens
     * emitidos cuando estaba encendida es justo el caso en el que este listener
     * hace falta.
     *
     * Los cuatro eventos los dispara spatie/laravel-permission sólo con
     * `permission.events_enabled = true` (ver `config/permission.php`), y por
     * eso ese flag no es opcional en este boilerplate.
     *
     * @see RevokeApiTokensOnPermissionChange
     */
    private function registerApiTokenRevocation(): void
    {
        Event::listen([
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
        ], RevokeApiTokensOnPermissionChange::class);
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

        /*
         * El switcher de cuentas es un atajo de desarrollo: sólo se registra
         * en `local`, igual que su ruta (`Auth/Routes/web.php`). Fuera de
         * local ni el alias existe, así que tampoco se puede montar a mano
         * desde otra vista.
         */
        if ($this->app->environment('local')) {
            Livewire::component('auth.dev-account-switcher', DevAccountSwitcher::class);
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
