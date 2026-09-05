<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Core\Actions\Action;
use App\Core\Enums\FileSyncStatus;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Mueve el fichero del disco de staging al disco de destino.
 *
 * Es la segunda mitad del guardado cuando `files.sync.enabled` está encendido:
 * la petición escribe en un disco local —rápido, sin red— y esto lo sube a S3 o
 * R2 desde la cola.
 *
 * ## El orden es lo único que importa aquí
 *
 * 1. Subir por **stream**, para no cargar en memoria un PDF de 40 MB.
 * 2. **Verificar** que existe en destino y que el tamaño coincide. Un `PUT` a
 *    S3 que devuelve 200 con el cuerpo cortado no es una hipótesis: es lo que
 *    pasa cuando la conexión se corta a mitad y el SDK no se entera.
 * 3. Sólo entonces apuntar la fila al disco nuevo.
 * 4. Y sólo entonces borrar la copia local.
 *
 * Cualquier otro orden tiene una ventana en la que el fichero no está en ningún
 * sitio alcanzable. Si el tamaño no cuadra se borra la copia remota —para no
 * dejar basura a medias— y se lanza: el listener reintenta y, si se agota, la
 * fila queda en `failed` con el fichero local intacto y sirviéndose.
 *
 * **Idempotente**: si ya está `synced` no hace nada. Un job que se reintenta
 * después de haber terminado no vuelve a subir el fichero ni borra el que ya
 * está bien.
 */
final class FileSyncAction extends Action
{
    public function handle(int $fileId): FileSyncStatus
    {
        $media = Media::find($fileId);

        if (! $media instanceof Media) {
            return FileSyncStatus::Failed;
        }

        $targetDisk = (string) $media->getCustomProperty(MediaSlots::SYNC_TARGET_DISK, $media->disk);

        if ((string) $media->getCustomProperty(MediaSlots::SYNC_STATUS) === FileSyncStatus::Synced->value
            || $targetDisk === $media->disk) {
            return $this->mark($media, FileSyncStatus::Synced);
        }

        $path = $media->getPathRelativeToRoot();

        try {
            /*
             * Resolver los discos va DENTRO del try: un `sync_target_disk` que
             * ya no está en `config/filesystems.php` —renombrado, borrado al
             * migrar de proveedor— lanza aquí mismo, y eso tiene que acabar en
             * `failed` con el fichero local intacto, no en un job muerto sin
             * dejar rastro en la fila.
             */
            $source = Storage::disk($media->disk);
            $target = Storage::disk($targetDisk);

            $stream = $source->readStream($path);

            if ($stream === null) {
                throw new RuntimeException("No se pudo leer el fichero local del archivo #{$fileId}.");
            }

            $target->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->assertCopyIsIntact($target, $source, $path, $fileId);
        } catch (Throwable) {
            return $this->mark($media, FileSyncStatus::Failed);
        }

        $media->disk = $targetDisk;
        $this->mark($media, FileSyncStatus::Synced);

        $source->delete($path);

        $directory = dirname($path);

        if ($directory !== '.' && $source->allFiles($directory) === []) {
            $source->deleteDirectory($directory);
        }

        return FileSyncStatus::Synced;
    }

    /**
     * La copia remota existe y pesa lo mismo que la local. Si no, se retira.
     *
     * @throws RuntimeException
     */
    private function assertCopyIsIntact(
        Filesystem $target,
        Filesystem $source,
        string $path,
        int $fileId,
    ): void {
        if (! $target->exists($path)) {
            throw new RuntimeException("El fichero del archivo #{$fileId} no llegó al disco de destino.");
        }

        $remoteSize = $target->size($path);
        $localSize = $source->size($path);

        if ($remoteSize !== $localSize) {
            $target->delete($path);

            throw new RuntimeException(
                "El fichero del archivo #{$fileId} llegó incompleto: local {$localSize}, remoto {$remoteSize}."
            );
        }
    }

    private function mark(Media $media, FileSyncStatus $status): FileSyncStatus
    {
        $media->setCustomProperty(MediaSlots::SYNC_STATUS, $status->value);
        $media->save();

        return $status;
    }
}
