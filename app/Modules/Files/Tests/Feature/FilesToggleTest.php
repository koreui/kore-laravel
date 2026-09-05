<?php

declare(strict_types=1);

use App\Core\Contracts\FileStore;
use App\Modules\Files\Console\Commands\FilesCleanupCommand;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Listeners\CompressStoredFile;
use App\Modules\Files\Listeners\SyncStoredFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| FILES_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra nada
| observable: ni el binding de `FileStore`, ni la ruta firmada, ni los
| listeners, ni el comando, ni su entrada en el scheduler.
|
| Dos excepciones documentadas, y las dos se comprueban aquí:
|
|   - el ESQUEMA: la tabla `media` se migra igual (un toggle apaga rutas y
|     comportamiento, nunca la forma de la base);
|   - el ESPACIO DE VISTAS: `<x-files::slot-upload>` tiene que resolver siempre,
|     porque Blade compila las etiquetas de componente al compilar la plantilla
|     y no al ejecutarla. Sin esto, la pantalla de usuarios reventaba en toda
|     instalación con el módulo apagado.
|
| La suite corre con el toggle apagado —`.env.example` lo trae en false y ése es
| el default de `config/kore-app.php`—, así que los casos "encendido" arrancan
| la aplicación de nuevo con `withEnvironment()` (tests/Pest.php).
|
*/

/**
 * Arranca la aplicación con el módulo encendido.
 *
 * @param array<string, string> $env variables extra para este arranque
 */
function withFilesToggleOn(Closure $callback, array $env = []): void
{
    withEnvironment(['FILES_ENABLED' => 'true', ...$env], $callback);
}

/**
 * Nombres de los comandos programados en el scheduler.
 *
 * @return Collection<int, string>
 */
function filesScheduledCommands(): Collection
{
    return collect(resolve(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command);
}

/*
|--------------------------------------------------------------------------
| Toggle apagado (estado por defecto de la suite)
|--------------------------------------------------------------------------
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.files.enabled'))->toBeFalse();
});

it('does not bind the FileStore contract with the toggle off', function (): void {
    // Resolverlo tiene que LANZAR, no devolver algo a medias: «esta instalación
    // no guarda archivos» es una respuesta, y un null a mitad de una subida no.
    expect(fn (): mixed => resolve(FileStore::class))
        ->toThrow(BindingResolutionException::class);
});

it('registers no serve route with the toggle off', function (): void {
    expect(Route::has('files.serve'))->toBeFalse();

    $this->get('/files/1')->assertNotFound();
});

it('does not register the cleanup command with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('files:cleanup');
});

it('schedules nothing files related with the toggle off', function (): void {
    expect(filesScheduledCommands()->contains(fn (string $command): bool => str_contains($command, 'files:cleanup')))
        ->toBeFalse();
});

it('does not listen to its own FileStored event with the toggle off', function (): void {
    expect(Event::hasListeners(FileStored::class))->toBeFalse();
});

it('migrates the media table even with the toggle off', function (): void {
    // El esquema NO depende del toggle: si dependiera, encender FILES_ENABLED
    // en producción exigiría una migración a mano con tráfico encima.
    expect(config('kore-app.files.enabled'))->toBeFalse()
        ->and(Schema::hasTable('media'))->toBeTrue()
        ->and(Schema::hasColumns('media', [
            'model_type', 'model_id', 'uuid', 'collection_name', 'name',
            'file_name', 'mime_type', 'disk', 'size', 'custom_properties',
        ]))->toBeTrue();
});

it('resolves the slot-upload component even with the toggle off', function (): void {
    // Blade compila `<x-files::slot-upload>` al compilar la plantilla que lo
    // usa, no al ejecutarla: un @if alrededor no lo salva. Si esta vista deja de
    // existir, la pantalla de edición de usuarios devuelve un 500 en toda
    // instalación con el módulo apagado.
    expect(View::exists('files::components.slot-upload'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('binds the FileStore contract with the toggle on', function (): void {
    withFilesToggleOn(function (): void {
        expect(resolve(FileStore::class))->toBeInstanceOf(FileStore::class)
            // Singleton: el cuaderno de marcas de tiempo que evita el N+1 de las
            // URLs sólo sirve si es el mismo objeto durante toda la petición.
            ->and(resolve(FileStore::class))->toBe(resolve(FileStore::class));
    });
});

it('registers the serve route with the toggle on', function (): void {
    withFilesToggleOn(function (): void {
        expect(Route::has('files.serve'))->toBeTrue()
            ->and(route('files.serve', ['file' => 7], absolute: false))->toStartWith('/files/7');
    });
});

it('registers the cleanup command and schedules it with the toggle on', function (): void {
    withFilesToggleOn(function (): void {
        expect(array_keys(Artisan::all()))->toContain('files:cleanup')
            ->and(Artisan::all()['files:cleanup'])->toBeInstanceOf(FilesCleanupCommand::class)
            ->and(filesScheduledCommands()->contains(fn (string $command): bool => str_contains($command, 'files:cleanup')))
            ->toBeTrue();
    });
});

it('registers no pipeline listener when compression and sync are both off', function (): void {
    withFilesToggleOn(function (): void {
        // Es el caso por defecto: sin compresión ni sincronización el archivo
        // nace donde tiene que estar y no hay nada que hacer después.
        expect(Event::hasListeners(FileStored::class))->toBeFalse();
    });
});

it('registers only the compression listener when compression is on', function (): void {
    // Comprimir cambia el fichero, así que subirlo antes sería subirlo dos
    // veces: con la compresión encendida es ella quien encadena el sync.
    withFilesToggleOn(function (): void {
        expect(Event::hasListeners(FileStored::class))->toBeTrue()
            ->and(filesListenersFor(FileStored::class))->toContain(CompressStoredFile::class)
            ->and(filesListenersFor(FileStored::class))->not->toContain(SyncStoredFile::class);
    }, ['FILES_COMPRESSION' => 'true', 'FILES_SYNC' => 'true']);
});

it('registers the sync listener when only sync is on', function (): void {
    withFilesToggleOn(function (): void {
        expect(filesListenersFor(FileStored::class))->toContain(SyncStoredFile::class)
            ->and(filesListenersFor(FileStored::class))->not->toContain(CompressStoredFile::class);
    }, ['FILES_SYNC' => 'true']);
});

/**
 * Clases de los listeners registrados para un evento.
 *
 * @return list<string>
 */
function filesListenersFor(string $event): array
{
    return array_values(array_map(
        static function (mixed $listener): string {
            if (! $listener instanceof Closure) {
                return (string) $listener;
            }

            // Laravel envuelve el listener de clase en una closure; el nombre
            // original queda en la variable capturada `$listener`.
            $captured = new ReflectionFunction($listener)->getStaticVariables();

            return is_string($captured['listener'] ?? null) ? $captured['listener'] : '';
        },
        Event::getRawListeners()[$event] ?? [],
    ));
}
