<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;
use App\Models\User;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Listeners\CompressStoredFile;
use App\Modules\Files\Listeners\SyncStoredFile;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| Los dos listeners de la tubería
|--------------------------------------------------------------------------
|
| Se invocan a mano en vez de por el evento: quién se registra y cuándo ya lo
| cubre `FilesToggleTest`, y aquí lo que interesa es qué hacen —y sobre todo que
| la compresión encadene el sync pase lo que pase, que es la única forma de que
| un archivo no se quede a medio camino cuando comprimirlo falla—.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('remoto');

    $this->owner = User::factory()->create();
});

/**
 * Guarda un archivo y devuelve su `Media`.
 */
function fileForListener(User $owner, UploadedFile $file): Media
{
    return resolve(FileStoreAction::class)->handle(
        $owner,
        $file,
        new FileSlotData(collection: 'documentos'),
        $owner->id,
    );
}

it('el listener de compresión encadena el sync cuando está encendido', function (): void {
    Config::set('files.sync.enabled', true);
    Config::set('files.disk', 'remoto');
    Config::set('files.staging_disk', 'local');

    $media = fileForListener($this->owner, UploadedFile::fake()->image('foto.jpg', 300, 300));

    resolve(CompressStoredFile::class)->handle(new FileStored((int) $media->getKey(), $media->mime_type));

    $media->refresh();

    expect($media->disk)->toBe('remoto')
        ->and($media->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Synced->value)
        ->and($media->getCustomProperty(MediaSlots::COMPRESSION_STATUS))
        ->not->toBe(FileCompressionStatus::Pending->value);
});

it('el listener de compresión no sincroniza nada con el sync apagado', function (): void {
    $media = fileForListener($this->owner, UploadedFile::fake()->create('datos.zip', 10, 'application/zip'));

    resolve(CompressStoredFile::class)->handle(new FileStored((int) $media->getKey(), $media->mime_type));

    expect($media->fresh()?->disk)->toBe('local')
        ->and($media->fresh()?->getCustomProperty(MediaSlots::COMPRESSION_STATUS))
        ->toBe(FileCompressionStatus::Skipped->value);
});

it('el listener de sync sube el fichero al disco de destino', function (): void {
    Config::set('files.sync.enabled', true);
    Config::set('files.disk', 'remoto');
    Config::set('files.staging_disk', 'local');

    $media = fileForListener($this->owner, UploadedFile::fake()->image('a.png'));

    resolve(SyncStoredFile::class)->handle(new FileStored((int) $media->getKey(), $media->mime_type));

    expect($media->fresh()?->disk)->toBe('remoto')
        ->and(Storage::disk('remoto')->exists($media->fresh()?->getPathRelativeToRoot() ?? ''))->toBeTrue();
});

it('el listener de sync se relanza para que la cola reintente cuando falla', function (): void {
    Config::set('files.sync.enabled', true);

    $media = fileForListener($this->owner, UploadedFile::fake()->image('a.png'));
    $media->setCustomProperty(MediaSlots::SYNC_TARGET_DISK, 'disco-inexistente');
    $media->setCustomProperty(MediaSlots::SYNC_STATUS, FileSyncStatus::Local->value);
    $media->save();

    $listener = resolve(SyncStoredFile::class);
    $evento = new FileStored((int) $media->getKey(), $media->mime_type);

    // Relanzar es lo que hace que el backoff de la cola entre en juego; la
    // Action ya dejó la fila en `failed`, así que agotar los intentos deja
    // escrito el estado correcto.
    expect(fn (): mixed => $listener->handle($evento))->toThrow(RuntimeException::class)
        ->and($media->fresh()?->getCustomProperty(MediaSlots::SYNC_STATUS))->toBe(FileSyncStatus::Failed->value)
        ->and($media->fresh()?->disk)->toBe('local');
});

it('los dos listeners son trabajo en cola, no trabajo de la petición', function (): void {
    // R3: no hay carpeta Jobs/ en un módulo. El trabajo asíncrono se modela como
    // listener ShouldQueue de un evento del módulo, que es lo que permite que
    // otro módulo se enganche sin tocar Files.
    expect(resolve(CompressStoredFile::class))->toBeInstanceOf(ShouldQueue::class)
        ->and(resolve(SyncStoredFile::class))->toBeInstanceOf(ShouldQueue::class);
});
