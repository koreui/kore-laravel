<?php

declare(strict_types=1);

namespace App\Modules\Mx\Providers;

use App\Modules\Mx\Console\Commands\SepomexImportCommand;
use App\Modules\Mx\Http\Livewire\PostalCodeField;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Módulo Mx detrás de `MX_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni las rutas
 * `api/v1/mx/*`, ni el componente Livewire `mx.postal-code-field`, ni el comando
 * `mx:sepomex:import`, ni las traducciones.
 *
 * Que el comando de importación también esté detrás del toggle es una decisión,
 * no un descuido: la tabla existe siempre, pero sin el módulo encendido nadie la
 * consulta, así que llenarla serían 145 000 filas inertes y un comando en el
 * cron que ya no significa nada. Encender `MX_ENABLED` es el paso que da sentido
 * a importar.
 *
 * **Dos cosas se registran siempre**, y ninguna es una válvula:
 *
 * - **Las migraciones.** El esquema no depende del toggle: si dependiera, dos
 *   instalaciones del mismo commit tendrían bases distintas según el `.env` del
 *   día en que se migró, y encender el módulo en producción exigiría una
 *   migración a mano con tráfico encima. Es el criterio de
 *   `docs/architecture/toggles.md`, el mismo de `devices` y de `media`.
 * - **`loadViewsFrom`.** Blade resuelve las etiquetas `<x-mx::…>` al **compilar**
 *   la plantilla que las usa, no al ejecutarla, así que un espacio de vistas
 *   registrado bajo el toggle deja un 500 en cualquier pantalla que las
 *   mencione. Registrar el espacio no expone nada mientras no haya rutas
 *   (precedente: `FilesModuleServiceProvider`).
 *
 * No hay `register()`: `MontoEnLetras` y `PostalCodes` no necesitan binding —el
 * contenedor las construye solas, no tienen dependencias— y así tampoco existen
 * como servicio cuando el módulo está apagado.
 *
 * Ver `docs/modules/mx.md`.
 */
final class MxModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre, las dos (ver el docblock).
        $this->loadMigrationsFrom("{$base}/Database/Migrations");
        $this->loadViewsFrom("{$base}/Resources/views", 'mx');

        if (! $this->isMxEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");

        Livewire::component('mx.postal-code-field', PostalCodeField::class);

        if ((bool) config('kore-app.api.enabled')) {
            $this->loadRoutesFrom("{$base}/Routes/api.php");
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SepomexImportCommand::class,
            ]);
        }
    }

    private function isMxEnabled(): bool
    {
        return (bool) config('kore-app.mx.enabled', false);
    }
}
