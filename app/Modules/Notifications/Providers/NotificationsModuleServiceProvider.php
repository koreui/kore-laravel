<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Core\Contracts\Notifier;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Notifications\Console\Commands\NotificationsPruneCommand;
use App\Modules\Notifications\Http\Livewire\NotificationBell;
use App\Modules\Notifications\Http\Livewire\NotificationSettings;
use App\Modules\Notifications\Http\Livewire\TableNotifications;
use App\Modules\Notifications\Listeners\NotifyOnApiTokenIssued;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Policies\NotificationPolicy;
use App\Modules\Notifications\Policies\NotificationPreferencePolicy;
use App\Modules\Notifications\Support\DatabaseNotifier;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Módulo Notifications detrás de `NOTIFICATIONS_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni el binding de
 * `App\Core\Contracts\Notifier`, ni las rutas web o de API, ni los tres
 * componentes Livewire —así la campana no existe ni siquiera como etiqueta que
 * el layout pudiera pintar—, ni las policies, ni el listener del evento de
 * Auth, ni `notifications:prune`, ni sus traducciones. Quien resuelva el
 * contrato recibe un `BindingResolutionException` —«esta instalación no
 * notifica»—, que es mejor respuesta que un aviso que desaparece en silencio;
 * por eso el layout pregunta antes por `config('kore-app.notifications.enabled')`.
 *
 * **Las migraciones son la excepción, y no es una válvula: es la regla.** Las
 * tablas `notifications` y `notification_preferences` se cargan siempre, también
 * con el toggle apagado, porque un toggle apaga rutas y comportamiento, nunca la
 * forma de la base (`docs/architecture/toggles.md`). El precedente es
 * `AUTH_PASSKEYS=false`, que deja de registrar la feature de Fortify y la
 * pantalla mientras la tabla `passkeys` se migra igual. Si la migración fuera
 * condicional, dos instalaciones del mismo commit tendrían bases distintas según
 * el `.env` del día en que se migró, y encender el toggle en producción exigiría
 * una migración a mano justo cuando ya hay tráfico.
 *
 * **`loadViewsFrom` también va siempre, y es la segunda excepción documentada de
 * R10.** Registrar un espacio de vistas que ninguna ruta pinta no expone nada
 * —sin componentes Livewire registrados no hay forma de montarlas—, y en cambio
 * quitarlo sí rompe algo: Larastan resuelve `view('notifications::pages.index')`
 * contra los namespaces registrados, y con el registro dentro del toggle el
 * análisis falla con «expects view-string» en las cuatro vistas del módulo. Es
 * el mismo criterio que ya aplican Docs y Files.
 *
 * `NotificationPreferences` se registra como `scoped()` —una instancia por
 * petición— porque cachea las preferencias resueltas: una corrida que avisa a
 * quince personas no debería consultar la misma fila quince veces.
 *
 * Ver `docs/modules/notifications.md`.
 */
final class NotificationsModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        'notifications.bell' => NotificationBell::class,
        'notifications.table-notifications' => TableNotifications::class,
        'notifications.settings' => NotificationSettings::class,
    ];

    public function register(): void
    {
        if (! $this->isNotificationsEnabled()) {
            return;
        }

        $this->app->scoped(NotificationPreferences::class);
        $this->app->singleton(Notifier::class, DatabaseNotifier::class);
    }

    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre: el esquema no depende del toggle (ver el docblock).
        $this->loadMigrationsFrom("{$base}/Database/Migrations");

        // También siempre, y por la segunda excepción de R10: sin el namespace
        // registrado, Larastan no puede validar `view('notifications::...')`.
        // Un espacio de vistas sin componentes que las monten no expone nada.
        $this->loadViewsFrom("{$base}/Resources/views", 'notifications');

        if (! $this->isNotificationsEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadRoutesFrom("{$base}/Routes/web.php");

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        /*
         * La bandeja no tiene permisos propios: lo que hay que decidir no es
         * «¿puede entrar?» sino «¿es suya?», y eso lo responde una Policy (R25).
         * `DatabaseNotification` es del framework, no del módulo, y eso está
         * bien: usar la tabla estándar es lo que hace que `markAsRead()`
         * funcione sin código propio.
         */
        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);
        Gate::policy(NotificationPreference::class, NotificationPreferencePolicy::class);

        /*
         * La única relación con Auth (R5): un evento suyo, tipado desde
         * `App\Modules\Auth\Events`, que es su frontera pública. Auth no sabe
         * que este módulo existe, y Devices escucha el mismo evento sin que
         * ninguno de los dos conozca al otro.
         */
        Event::listen(ApiTokenIssued::class, NotifyOnApiTokenIssued::class);

        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                NotificationsPruneCommand::class,
            ]);
        }
    }

    private function isNotificationsEnabled(): bool
    {
        return (bool) config('kore-app.notifications.enabled', false);
    }
}
