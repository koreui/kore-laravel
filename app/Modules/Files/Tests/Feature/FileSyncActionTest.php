<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Core\Enums\FileSyncStatus;
use App\Models\User;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Actions\FileSyncAction;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| FileSyncAction — staging → destino
|--------------------------------------------------------------------------
|
| El disco «remoto» es otro disco falso: lo que se prueba es el ORDEN —subir,
| verificar, apuntar la fila, borrar la copia local— y que ninguna de las ramas
| deja el fichero fuera del alcance de nadie.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('remoto');

    Config::set('files.sync.enabled', true);
    Config::set('files.disk', 'remoto');
    Config::set('files.staging_disk', 'local');

    $this->owner = User::factory()->create();
    $this->sync = resolve(FileSyncAction::class);

    $this->media = resolve(FileStoreAction::class)->handle(
        $this->owner,
        UploadedFile::fake()->image('a.png'),
        new FileSlotData(collection: 'avatar'),
        $this->owner->id,
    );
});

it('mueve el fichero al disco de destino y borra la copia local', function (): void {
    $path = $this->media->getPathRelativeToRoot();

    expect($this->sync->handle((int) $this->media->getKey()))->toBe(FileSyncStatus::Synced);

    $this->media->refresh();

    expect($this->media->disk)->toBe('remoto')
        ->and($this->media->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Synced->value)
        ->and(Storage::disk('remoto')->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('es idempotente: sincronizar dos veces no vuelve a subir nada', function (): void {
    $this->sync->handle((int) $this->media->getKey());

    // La segunda pasada no puede fallar por no encontrar la copia local, que ya
    // no está: un job reintentado después de haber terminado es lo normal.
    expect($this->sync->handle((int) $this->media->getKey()))->toBe(FileSyncStatus::Synced)
        ->and(Storage::disk('remoto')->exists($this->media->fresh()?->getPathRelativeToRoot() ?? ''))->toBeTrue();
});

it('marca failed y deja el fichero local cuando el destino no existe', function (): void {
    $this->media->setCustomProperty(MediaSlots::SYNC_TARGET_DISK, 'disco-inexistente');
    $this->media->save();

    $path = $this->media->getPathRelativeToRoot();

    expect($this->sync->handle((int) $this->media->getKey()))->toBe(FileSyncStatus::Failed);

    $this->media->refresh();

    // Un fallo de sincronización degrada el coste, nunca el acceso: la fila
    // sigue apuntando al disco local y el fichero sigue ahí.
    expect($this->media->disk)->toBe('local')
        ->and($this->media->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Failed->value)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('un id que no existe se marca como fallo y no revienta', function (): void {
    expect($this->sync->handle(999_999))->toBe(FileSyncStatus::Failed)
        ->and(Media::count())->toBe(1);
});
