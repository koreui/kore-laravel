<?php

declare(strict_types=1);

namespace App\Modules\Devices\Providers;

use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Auth\Events\ApiTokenRevoked;
use App\Modules\Devices\Console\Commands\DevicesCleanupCommand;
use App\Modules\Devices\Http\Middleware\EnsureClientVersion;
use App\Modules\Devices\Listeners\RegisterDeviceOnTokenIssued;
use App\Modules\Devices\Listeners\RevokeDeviceOnTokenRevoked;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Policies\DevicePolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Devices detrás de `DEVICES_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni rutas, ni el
 * alias `devices.version`, ni la policy, ni los listeners de los eventos de
 * Auth, ni el comando `devices:cleanup`, ni sus traducciones. Un login por API
 * sigue funcionando exactamente igual, sólo que nadie apunta desde dónde.
 *
 * **La migración es la excepción, y no es una válvula: es la regla.** El
 * esquema se carga siempre, también con el toggle apagado, porque un toggle
 * apaga rutas y comportamiento, nunca la forma de la base
 * (`docs/architecture/toggles.md`). El precedente es `AUTH_PASSKEYS=false`, que
 * deja de registrar la feature de Fortify y la pantalla mientras la tabla
 * `passkeys` se migra igual. Si la migración fuera condicional, dos
 * instalaciones del mismo commit tendrían bases distintas según el `.env` del
 * día en que se migró, y encender el toggle en producción exigiría una
 * migración a mano justo cuando ya hay tráfico.
 *
 * No hay `register()`: nada del módulo necesita binding —el contenedor resuelve
 * las Actions y el controller solo— y así tampoco existe cuando está apagado.
 *
 * Ver `docs/modules/devices.md`.
 */
final class DevicesModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre: el esquema no depende del toggle (ver el docblock).
        $this->loadMigrationsFrom("{$base}/Database/Migrations");

        if (! $this->isDevicesEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");

        Gate::policy(Device::class, DevicePolicy::class);

        /*
         * El alias existe, pero ninguna ruta lo lleva puesta: `devices.version`
         * es opt-in por endpoint y NO va en el grupo `api`. El día que se sube
         * `devices.min_app_version`, el login y el propio listado de
         * dispositivos tienen que seguir respondiendo para que la app pueda
         * decirle al usuario qué hacer.
         */
        Route::aliasMiddleware('devices.version', EnsureClientVersion::class);

        /*
         * La única relación con Auth (R5): dos eventos suyos, tipados desde
         * `App\Modules\Auth\Events`, que es su frontera pública. Auth no sabe
         * que este módulo existe.
         */
        Event::listen(ApiTokenIssued::class, RegisterDeviceOnTokenIssued::class);
        Event::listen(ApiTokenRevoked::class, RevokeDeviceOnTokenRevoked::class);

        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                DevicesCleanupCommand::class,
            ]);
        }
    }

    private function isDevicesEnabled(): bool
    {
        return (bool) config('kore-app.devices.enabled', false);
    }
}
