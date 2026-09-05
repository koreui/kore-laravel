<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;
use App\Models\User;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| FileStoreAction — versionado por slot
|--------------------------------------------------------------------------
|
| La Action no depende del toggle: lo que el toggle apaga es el binding del
| contrato, no la capacidad de la clase. Por eso estos tests la resuelven
| directamente y no arrancan la aplicación de nuevo.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake('remoto');

    $this->owner = User::factory()->create();
    $this->slot = new FileSlotData(collection: 'avatar');
    $this->store = resolve(FileStoreAction::class);
});

it('guarda la primera versión de un slot como vigente', function (): void {
    $media = $this->store->handle(
        $this->owner,
        UploadedFile::fake()->image('perfil.png'),
        $this->slot,
        $this->owner->id,
    );

    expect(MediaSlots::versionOf($media))->toBe(1)
        ->and($media->getCustomProperty(MediaSlots::IS_CURRENT))->toBeTrue()
        ->and($media->getCustomProperty(MediaSlots::UPLOADED_BY))->toBe($this->owner->id)
        ->and($media->getCustomProperty(MediaSlots::REPLACED_AT))->toBeNull()
        ->and($media->getCustomProperty(MediaSlots::FINGERPRINT))->toBe($this->slot->fingerprint())
        ->and($media->collection_name)->toBe('avatar')
        ->and($media->file_name)->toBe('perfil.png')
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('la segunda subida al mismo slot crea la versión 2 y archiva la 1', function (): void {
    $primera = $this->store->handle($this->owner, UploadedFile::fake()->image('uno.png'), $this->slot, $this->owner->id);
    $segunda = $this->store->handle($this->owner, UploadedFile::fake()->image('dos.png'), $this->slot, $this->owner->id);

    $primera->refresh();

    expect(MediaSlots::versionOf($segunda))->toBe(2)
        ->and($segunda->getCustomProperty(MediaSlots::IS_CURRENT))->toBeTrue()
        ->and($primera->getCustomProperty(MediaSlots::IS_CURRENT))->toBeFalse()
        ->and($primera->getCustomProperty(MediaSlots::REPLACED_AT))->toBeString()
        // Archivar NO destruye: el fichero de la versión 1 sigue en disco, que
        // es la diferencia entre reemplazar y borrar.
        ->and(Storage::disk('local')->exists($primera->getPathRelativeToRoot()))->toBeTrue()
        ->and(Media::count())->toBe(2);
});

it('dos slots de la misma colección no comparten historial', function (): void {
    $contrato = new FileSlotData(collection: 'documentos', key: ['tipo' => 'contrato']);
    $anexo = new FileSlotData(collection: 'documentos', key: ['tipo' => 'anexo']);

    $this->store->handle($this->owner, UploadedFile::fake()->create('c.pdf'), $contrato, $this->owner->id);
    $delAnexo = $this->store->handle($this->owner, UploadedFile::fake()->create('a.pdf'), $anexo, $this->owner->id);

    // Cada slot empieza su propia numeración, y subir en uno no archiva el otro.
    expect(MediaSlots::versionOf($delAnexo))->toBe(1)
        ->and(MediaSlots::current($this->owner, $contrato)?->getCustomProperty(MediaSlots::IS_CURRENT))->toBeTrue()
        ->and(MediaSlots::current($this->owner, $anexo)?->getKey())->toBe($delAnexo->getKey());
});

it('el orden de las claves del slot no abre un slot nuevo', function (): void {
    $unOrden = new FileSlotData(collection: 'documentos', key: ['tipo' => 'acta', 'anio' => 2026]);
    $elOtro = new FileSlotData(collection: 'documentos', key: ['anio' => 2026, 'tipo' => 'acta']);

    $this->store->handle($this->owner, UploadedFile::fake()->create('a.pdf'), $unOrden, $this->owner->id);
    $segunda = $this->store->handle($this->owner, UploadedFile::fake()->create('b.pdf'), $elOtro, $this->owner->id);

    expect(MediaSlots::versionOf($segunda))->toBe(2);
});

it('un slot público va al disco público y uno privado al privado', function (): void {
    $publico = $this->store->handle(
        $this->owner,
        UploadedFile::fake()->image('logo.png'),
        new FileSlotData(collection: 'branding', public: true),
        $this->owner->id,
    );

    $privado = $this->store->handle(
        $this->owner,
        UploadedFile::fake()->create('nomina.pdf'),
        new FileSlotData(collection: 'nominas'),
        $this->owner->id,
    );

    expect($publico->disk)->toBe('public')
        ->and($privado->disk)->toBe('local');
});

it('nace pendiente de comprimir y ya sincronizado cuando el sync está apagado', function (): void {
    $media = $this->store->handle($this->owner, UploadedFile::fake()->image('a.png'), $this->slot, $this->owner->id);

    // Sin sincronización el fichero se escribe directamente en su disco: no hay
    // ventana entre guardar y estar donde toca.
    expect($media->getCustomProperty(MediaSlots::COMPRESSION_STATUS))->toBe(FileCompressionStatus::Pending->value)
        ->and($media->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Synced->value);
});

it('con el sync encendido escribe en staging y queda pendiente de subir', function (): void {
    Config::set('files.sync.enabled', true);
    Config::set('files.disk', 'remoto');
    Config::set('files.staging_disk', 'local');

    $media = $this->store->handle($this->owner, UploadedFile::fake()->image('a.png'), $this->slot, $this->owner->id);

    expect($media->disk)->toBe('local')
        ->and($media->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Local->value)
        ->and($media->getCustomProperty(MediaSlots::SYNC_TARGET_DISK))->toBe('remoto')
        ->and(Storage::disk('remoto')->exists($media->getPathRelativeToRoot()))->toBeFalse();
});

it('dispara FileStored con el id y el mime', function (): void {
    Event::fake([FileStored::class]);

    $media = $this->store->handle($this->owner, UploadedFile::fake()->image('a.png'), $this->slot, $this->owner->id);

    Event::assertDispatched(FileStored::class, fn (FileStored $event): bool => $event->fileId === (int) $media->getKey()
        && $event->mimeType === $media->mime_type);
});
