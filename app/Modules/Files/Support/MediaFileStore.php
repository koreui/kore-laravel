<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Core\Contracts\FileStore;
use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use App\Modules\Files\Actions\FileArchiveAction;
use App\Modules\Files\Actions\FileDeleteAction;
use App\Modules\Files\Actions\FileStoreAction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * `FileStore` sobre spatie/laravel-medialibrary.
 *
 * Es un **adaptador**, no un servicio: no escribe nada por su cuenta. Cada
 * operación de escritura vive en su Action —que es quien puede correr desde un
 * job, un comando o un seeder— y esto sólo las compone y traduce el resultado a
 * los DTOs de `Core` (R4). La dirección es siempre la misma y no se mezcla: las
 * Actions son las dueñas de la escritura, el store es la puerta.
 *
 * Se bindea como singleton en `FilesModuleServiceProvider::register()` y sólo
 * con el toggle encendido. Singleton por el cuaderno de `$versionTokens` que se
 * explica en {@see self::url()}.
 */
final class MediaFileStore implements FileStore
{
    /**
     * `id del archivo => marca de tiempo de su fichero`, de lo visto en esta
     * petición. Ver {@see self::url()}.
     *
     * @var array<int, int>
     */
    private array $versionTokens = [];

    public function __construct(
        private readonly FileStoreAction $storeFile,
        private readonly FileArchiveAction $archiveFile,
        private readonly FileDeleteAction $deleteFile,
    ) {}

    public function store(HasMedia $owner, UploadedFile $file, FileSlotData $slot, int $uploadedBy): StoredFileData
    {
        return $this->toData(
            $this->storeFile->handle($owner, $file, $slot, $uploadedBy)
        );
    }

    public function current(HasMedia $owner, FileSlotData $slot): ?StoredFileData
    {
        $media = MediaSlots::current($owner, $slot);

        return $media instanceof Media ? $this->toData($media) : null;
    }

    /**
     * @return Collection<int, StoredFileData>
     */
    public function history(HasMedia $owner, FileSlotData $slot): Collection
    {
        return MediaSlots::versions($owner, $slot)->map($this->toData(...));
    }

    public function archive(int $fileId): void
    {
        $this->archiveFile->handle($fileId);
        unset($this->versionTokens[$fileId]);
    }

    /**
     * URL temporal firmada que sirve `FileServeController`.
     *
     * **El `v` va DENTRO de lo firmado.** Es la lección que dejó asper-server:
     * cuando un fichero se sobrescribe en su sitio —rotar una imagen, sustituir
     * un PDF sin abrir versión nueva, comprimirlo— la URL no cambiaría, y el
     * navegador (y `expo-image`, y cualquier CDN por delante) seguiría enseñando
     * la copia cacheada. La marca de tiempo de la fila la cambia. Y va **dentro**
     * de la firma y no pegada detrás con un `&v=`: un parámetro añadido a mano a
     * una URL firmada la invalida, porque la firma cubre la query entera.
     *
     * **De dónde sale la marca sin volver a la base.** Quien pide una URL casi
     * siempre acaba de recibir el `StoredFileData` de `current()` o de
     * `history()`; ahí el `Media` estaba en la mano, así que su marca se apunta
     * en `$versionTokens` y aquí se reutiliza. Es lo que permite pintar el
     * avatar de veinticinco filas con las dos consultas del listado y ninguna
     * más. Un id que no se ha visto —una URL construida desde un id guardado en
     * otro sitio— sí va a la base.
     *
     * Devuelve siempre la ruta de la aplicación, también cuando el fichero ya
     * está en S3: quien redirige a la URL temporal del bucket es el controller.
     * Así hay **una sola forma** de enlazar un archivo, y mover un disco de
     * local a remoto no obliga a repasar las plantillas.
     */
    public function url(int $fileId, ?int $minutes = null): string
    {
        $minutes ??= (int) config('files.url_ttl_minutes', 30);

        return URL::temporarySignedRoute(
            'files.serve',
            CarbonImmutable::now()->addMinutes($minutes),
            [
                'file' => $fileId,
                'v' => $this->versionTokens[$fileId] ?? $this->rememberToken(Media::findOrFail($fileId)),
            ],
        );
    }

    public function delete(int $fileId): void
    {
        $this->deleteFile->handle($fileId);
        unset($this->versionTokens[$fileId]);
    }

    /**
     * Traduce y, de paso, apunta la marca de tiempo del fichero.
     */
    private function toData(Media $media): StoredFileData
    {
        $this->rememberToken($media);

        return MediaSlots::toData($media);
    }

    /**
     * Apunta —y devuelve— la marca de tiempo del fichero de este archivo.
     *
     * Un `updated_at` nulo cuenta como `0`: la columna es `nullable` en la tabla
     * del paquete, y una fila escrita por otro camino no puede dejar sin URL a
     * un fichero que sí existe. Lo que se pierde entonces es sólo la
     * invalidación de caché, no el acceso.
     */
    private function rememberToken(Media $media): int
    {
        $updatedAt = $media->updated_at;

        return $this->versionTokens[(int) $media->getKey()] = $updatedAt instanceof DateTimeInterface
            ? $updatedAt->getTimestamp()
            : 0;
    }
}
