<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Core\Actions\Action;
use App\Core\Data\FileSlotData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Support\MediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Guarda un fichero como la versión siguiente de un slot.
 *
 * Es la única escritura de alta del módulo: `MediaFileStore` la compone, y
 * nadie más añade filas a `media`.
 *
 * ## El orden importa, y es el mismo que en Notarium
 *
 * 1. Se calcula la versión siguiente **antes** de escribir nada.
 * 2. Se guarda el fichero.
 * 3. Y **sólo entonces** se archivan las versiones anteriores del slot.
 *
 * Al revés —archivar primero y guardar después— un fallo al escribir dejaría el
 * slot vacío: el archivo que había ya no sería el vigente y el nuevo no
 * existiría. Es la diferencia entre «se cayó la subida» y «se perdió el
 * documento».
 *
 * ## Dónde se escribe
 *
 * Con `files.sync.enabled` apagado —el caso por defecto— el fichero va directo
 * a su disco (`files.disk` o `files.public_disk`) y nace `synced`: ya está donde
 * tiene que estar. Con la sincronización encendida va primero al disco de
 * staging y nace `local`, y es el listener en cola quien lo mueve. Esa es toda
 * la diferencia, y por eso el resto del módulo no pregunta por el toggle sino
 * por `sync_status`.
 */
final class FileStoreAction extends Action
{
    public function handle(HasMedia $owner, UploadedFile $file, FileSlotData $slot, int $uploadedBy): Media
    {
        $targetDisk = $slot->public
            ? (string) config('files.public_disk', 'public')
            : (string) config('files.disk', 'local');

        $syncing = (bool) config('files.sync.enabled', false);
        $writeDisk = $syncing ? (string) config('files.staging_disk', 'local') : $targetDisk;

        $version = $this->nextVersion($owner, $slot);

        /*
         * El nombre original se conserva: es lo que ve quien subió el archivo y
         * lo que espera al descargarlo. Que no sea un problema lo garantizan
         * tres cosas del propio paquete —sanea el nombre, rechaza las
         * extensiones de `media-library.disallowed_extensions` (`.php` incluida,
         * también en `documento.php.pdf`) y guarda cada versión en su propia
         * carpeta, así que dos ficheros que se llamen igual no se pisan—. Lo
         * que nunca sale del nombre es la ruta: ésa la fija `SlotPathGenerator`.
         */
        $media = $owner->addMedia($file)
            ->usingFileName($file->getClientOriginalName())
            ->withCustomProperties([
                MediaSlots::FINGERPRINT => $slot->fingerprint(),
                MediaSlots::KEY => $slot->key,
                MediaSlots::VERSION => $version,
                MediaSlots::IS_CURRENT => true,
                MediaSlots::UPLOADED_BY => $uploadedBy,
                MediaSlots::REPLACED_AT => null,
                MediaSlots::COMPRESSION_STATUS => FileCompressionStatus::Pending->value,
                MediaSlots::SYNC_STATUS => ($syncing ? FileSyncStatus::Local : FileSyncStatus::Synced)->value,
                MediaSlots::SYNC_TARGET_DISK => $targetDisk,
            ])
            ->toMediaCollection($slot->collection, $writeDisk);

        $this->supersedePreviousVersions($owner, $slot, (int) $media->getKey());

        event(new FileStored(fileId: (int) $media->getKey(), mimeType: $media->mime_type));

        return $media;
    }

    /**
     * El número que le toca a la versión nueva.
     *
     * Se mira el máximo de **todas** las versiones del slot, archivadas
     * incluidas: los números no se reciclan, así que la versión 3 sigue siendo
     * la 3 aunque la 2 se haya purgado.
     */
    private function nextVersion(HasMedia $owner, FileSlotData $slot): int
    {
        $max = MediaSlots::query($owner, $slot)
            ->get()
            ->max(static fn (Media $media): int => MediaSlots::versionOf($media));

        return (int) ($max ?? 0) + 1;
    }

    /**
     * Marca como no vigentes las versiones anteriores del slot.
     *
     * En la práctica sólo hay una, pero se recorren todas: si una corrida
     * anterior murió a medias, esto vuelve a dejar el slot con una sola vigente
     * en vez de arrastrar el desorden.
     */
    private function supersedePreviousVersions(HasMedia $owner, FileSlotData $slot, int $newMediaId): void
    {
        $now = CarbonImmutable::now()->toIso8601String();

        MediaSlots::query($owner, $slot)
            ->whereKeyNot($newMediaId)
            ->get()
            ->each(static function (Media $media) use ($now): void {
                if (! (bool) $media->getCustomProperty(MediaSlots::IS_CURRENT, true)) {
                    return;
                }

                $media->setCustomProperty(MediaSlots::IS_CURRENT, false);
                $media->setCustomProperty(MediaSlots::REPLACED_AT, $now);
                $media->save();
            });
    }
}
