<?php

declare(strict_types=1);

namespace App\Modules\Files\Providers;

use App\Core\Contracts\FileStore;
use App\Modules\Files\Console\Commands\FilesCleanupCommand;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Listeners\CompressStoredFile;
use App\Modules\Files\Listeners\SyncStoredFile;
use App\Modules\Files\Support\MediaFileStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Files detrás de `FILES_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni el binding de
 * `App\Core\Contracts\FileStore`, ni la ruta `files.serve`, ni los listeners, ni
 * el comando `files:cleanup`, ni sus traducciones. Quien resuelva el contrato
 * recibe un
 * `BindingResolutionException` —«esta instalación no guarda archivos»—, que es
 * mejor respuesta que un `null` a mitad de una subida; por eso las pantallas que
 * lo usan preguntan antes por `config('kore-app.files.enabled')`.
 *
 * **La migración es la excepción, y no es una válvula: es la regla.** La tabla
 * `media` se carga siempre, también con el toggle apagado, porque un toggle
 * apaga rutas y comportamiento, nunca la forma de la base
 * (`docs/architecture/toggles.md`). El precedente es `AUTH_PASSKEYS=false`, que
 * deja de registrar la feature de Fortify y la pantalla mientras la tabla
 * `passkeys` se migra igual. Si la migración fuera condicional, dos
 * instalaciones del mismo commit tendrían bases distintas según el `.env` del
 * día en que se migró, y encender el toggle en producción exigiría una migración
 * a mano justo cuando ya hay tráfico.
 *
 * **Los dos listeners nunca corren los dos sobre el mismo archivo.** Comprimir
 * cambia el fichero, así que subirlo a su disco antes de comprimirlo sería
 * subirlo dos veces: cuando la compresión está encendida es ella la que encadena
 * la sincronización al terminar, y `SyncStoredFile` sólo se registra cuando la
 * compresión está apagada. Ese reparto se decide aquí, en un sitio, y no dentro
 * de cada listener.
 *
 * Ver `docs/modules/files.md`.
 */
final class FilesModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->isFilesEnabled()) {
            return;
        }

        $this->app->singleton(FileStore::class, MediaFileStore::class);
    }

    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre: el esquema no depende del toggle (ver el docblock).
        $this->loadMigrationsFrom("{$base}/Database/Migrations");

        /*
         * También siempre, y es la segunda excepción documentada de R10.
         *
         * `loadViewsFrom` con espacio de nombres es lo que hace que
         * `<x-files::slot-upload>` resuelva a
         * `Resources/views/components/slot-upload.blade.php`, y Blade compila
         * las etiquetas de componente **al compilar la plantilla**, no al
         * ejecutarla: un `@if (config(…))` alrededor no evita nada, porque para
         * cuando el `if` se evalúa el componente ya tuvo que existir. Con el
         * registro dentro del toggle, `users::livewire.form-component` reventaba
         * con «Unable to locate a class or view for component
         * [files::slot-upload]» en toda instalación con FILES_ENABLED=false.
         *
         * Registrarlo siempre no expone nada: un espacio de vistas sin rutas
         * que lo pinten es exactamente el caso que la excepción de R10
         * contempla («sin rutas no expone nada»). Lo que el toggle sigue
         * apagando es que el componente **funcione**: sin binding de
         * `FileStore` no hay `$this->avatar` que pasarle, y las pantallas
         * preguntan por el toggle antes de pintarlo.
         */
        $this->loadViewsFrom("{$base}/Resources/views", 'files');

        if (! $this->isFilesEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadRoutesFrom("{$base}/Routes/web.php");

        if ((bool) config('files.compression.enabled', false)) {
            Event::listen(FileStored::class, CompressStoredFile::class);
        } elseif ((bool) config('files.sync.enabled', false)) {
            Event::listen(FileStored::class, SyncStoredFile::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                FilesCleanupCommand::class,
            ]);
        }
    }

    private function isFilesEnabled(): bool
    {
        return (bool) config('kore-app.files.enabled', false);
    }
}
