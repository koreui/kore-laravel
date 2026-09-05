<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Core\Contracts\InstallationFeatures;
use App\Core\Contracts\NumberSeries;
use App\Core\Contracts\Settings;
use App\Core\Data\OrganizationData;
use App\Modules\Platform\Console\Commands\FeaturesListCommand;
use App\Modules\Platform\Console\Commands\SettingsShowCommand;
use App\Modules\Platform\Http\Livewire\SettingsForm;
use App\Modules\Platform\Http\Middleware\EnsureFeatureEnabled;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Policies\SettingPolicy;
use App\Modules\Platform\Support\ConfigFeatures;
use App\Modules\Platform\Support\DatabaseNumberSeries;
use App\Modules\Platform\Support\DatabaseSettings;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Módulo Platform: la infraestructura que toda instalación tiene.
 *
 * **No lleva toggle, y es la diferencia con Devices, Pdf y Files.** Un toggle
 * existe para que un derivado pueda no pagar el precio de una capacidad que no
 * usa: Gotenberg corriendo, media-library instalado, un inventario de
 * dispositivos que mantener. Aquí no hay precio que ahorrar —dos tablas
 * pequeñas y tres contratos— y sí hay algo que perder: si `Settings` pudiera no
 * estar bindeado, cada consumidor tendría que preguntar antes de resolverlo, y
 * el layout —que pinta el nombre de la organización en todas las pantallas—
 * tendría que llevar un `@if` alrededor para siempre. Un contrato que a veces no
 * existe no es una frontera, es una condición.
 *
 * Por eso el provider no tiene early return y `register()` bindea siempre. Es el
 * mismo trato que Users y Auth.
 *
 * Tres contratos, tres implementaciones:
 *
 *   `Settings`             → `DatabaseSettings`      (tabla `settings`)
 *   `NumberSeries`         → `DatabaseNumberSeries`  (tabla `number_sequences`)
 *   `InstallationFeatures` → `ConfigFeatures`        (`config/features.php`)
 *
 * Ver `docs/modules/platform.md`.
 */
final class PlatformModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        'platform.settings-form' => SettingsForm::class,
    ];

    public function register(): void
    {
        /*
         * Singletons y no `bind`: `DatabaseSettings` memoiza el mapa de ajustes
         * durante la petición, y con un binding transitorio esa memoria se
         * perdería en cada resolución —que es justo lo que pasa cuando el
         * layout, un correo y un PDF preguntan lo mismo tres veces.
         */
        $this->app->singleton(Settings::class, DatabaseSettings::class);
        $this->app->singleton(NumberSeries::class, DatabaseNumberSeries::class);
        $this->app->singleton(InstallationFeatures::class, ConfigFeatures::class);
    }

    public function boot(): void
    {
        $base = __DIR__.'/..';

        $this->loadMigrationsFrom("{$base}/Database/Migrations");
        $this->loadRoutesFrom("{$base}/Routes/web.php");
        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadViewsFrom("{$base}/Resources/views", 'platform');
        Blade::anonymousComponentPath("{$base}/Resources/views", 'platform');

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        Gate::policy(Setting::class, SettingPolicy::class);

        /*
         * `feature:{clave}` — cierra una ruta cuyo módulo no está incluido en
         * esta instalación. Ninguna ruta del boilerplate lo lleva puesto: es
         * para los módulos opcionales de un derivado, y ponérselo a una pantalla
         * del núcleo sería poder vender un producto que no se puede configurar.
         */
        Route::aliasMiddleware('feature', EnsureFeatureEnabled::class);

        $this->registerFeatureDirective();
        $this->composeOrganizationIntoLayouts();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SettingsShowCommand::class,
                FeaturesListCommand::class,
            ]);
        }
    }

    /**
     * `@feature('reports') … @endfeature` en una Blade.
     *
     * Es el equivalente en vista del middleware: esconder el enlace a un módulo
     * que esta instalación no incluye. No sustituye al middleware —una vista que
     * no pinta el enlace no protege la ruta—, igual que `@can` no sustituye a la
     * Policy.
     */
    private function registerFeatureDirective(): void
    {
        Blade::if('feature', static fn (string $feature): bool => resolve(InstallationFeatures::class)->enabled($feature));
    }

    /**
     * Inyecta `$organization` en los layouts.
     *
     * Un composer y no un `View::share`: `share` se evalúa en cada petición
     * aunque la respuesta sea un JSON de la API o un 302 del login, y esto lee
     * la base. Con el composer, la consulta sólo ocurre cuando de verdad se
     * pinta un layout.
     *
     * Y una consulta, no siete: `DatabaseSettings` trae el mapa entero de la
     * caché y compone el DTO en memoria. Lo que llega a la Blade es
     * `App\Core\Data\OrganizationData`, no el modelo `Setting` (R30).
     *
     * El comodín cubre `components.layouts.app` y `components.layouts.public`:
     * los layouts son componentes anónimos, y su nombre de vista es el de su
     * archivo bajo `resources/views`.
     */
    private function composeOrganizationIntoLayouts(): void
    {
        View::composer('components.layouts.*', static function (ViewContract $view): void {
            $view->with(
                'organization',
                OrganizationData::fromSettings(resolve(Settings::class)->all()),
            );
        });
    }
}
