<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Models\User;
use App\Modules\Users\Http\Livewire\AccountStatusPanel;
use App\Modules\Users\Http\Livewire\FormComponent;
use App\Modules\Users\Http\Livewire\TableUsers;
use App\Modules\Users\Policies\UserPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class UsersModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        'users.form-component' => FormComponent::class,
        'users.table-users' => TableUsers::class,
    ];

    public function boot(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");

        // Mismo patrón que Auth: con el toggle apagado la API del módulo no
        // existe, ni siquiera como ruta que devuelve 401 (R10).
        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadViewsFrom("{$base}/Resources/views", 'users');
        Blade::anonymousComponentPath("{$base}/Resources/views", 'users');

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        /*
         * El panel de estado de la cuenta sólo existe con `AUTH_INVITATIONS`
         * encendido: es la palanca del estado que ese toggle pone en marcha, y
         * registrarlo apagado dejaría montable —desde cualquier vista de un
         * derivado— un componente que mueve una columna que nadie mira (R10).
         *
         * El toggle es de Auth y lo lee Users: R5 prohíbe importar clases entre
         * módulos, no leer una clave de configuración compartida. La pantalla de
         * edición pregunta lo mismo antes de pintarlo.
         */
        if ((bool) config('kore-app.auth.invitations')) {
            Livewire::component('users.account-status-panel', AccountStatusPanel::class);
        }

        Gate::policy(User::class, UserPolicy::class);
    }
}
