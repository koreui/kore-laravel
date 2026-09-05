<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Las dos operaciones que todo el módulo repite sobre `Media`: buscar las
 * versiones de un slot y traducir una fila a `StoredFileData`.
 *
 * Está aquí y no en cada Action porque las tres Actions de escritura, el store
 * y el comando de limpieza necesitan exactamente esto y sólo esto. No es una
 * capa: es el vocabulario del módulo escrito una vez.
 *
 * **Qué se pregunta a la base y qué no.** La huella del slot es una cadena de
 * longitud fija (ver `FileSlotData::fingerprint()`) y
 * `where('custom_properties->slot_fingerprint', …)` la compara igual en SQLite,
 * MySQL y Postgres; ahí se gana el salto respecto de Notarium, que traía toda la
 * colección del modelo y filtraba en PHP. Lo que **no** viaja igual de bien son
 * los booleanos dentro de un JSON —SQLite guarda `true` y `1` en la misma
 * columna y cada motor los extrae a su manera—, así que `is_current` se decide
 * en memoria, sobre el puñado de versiones que un slot llega a tener.
 *
 * Y si la relación `media` ya viene cargada, no se pregunta nada: ver
 * {@see versions()}.
 */
final class MediaSlots
{
    /** Huella del slot: lo que identifica el hueco (ver `FileSlotData`). */
    public const string FINGERPRINT = 'slot_fingerprint';

    /** La `key` del slot, guardada tal cual para poder leer la fila a ojo. */
    public const string KEY = 'slot_key';

    /** Número de versión dentro del slot, empezando en 1. */
    public const string VERSION = 'version';

    /** ¿Es ésta la versión vigente del slot? */
    public const string IS_CURRENT = 'is_current';

    /** Id de quien la subió. */
    public const string UPLOADED_BY = 'uploaded_by';

    /** Cuándo dejó de ser la vigente (ISO 8601), o null si lo sigue siendo. */
    public const string REPLACED_AT = 'replaced_at';

    /** Valor de `App\Core\Enums\FileCompressionStatus`. */
    public const string COMPRESSION_STATUS = 'compression_status';

    /** Valor de `App\Core\Enums\FileSyncStatus`. */
    public const string SYNC_STATUS = 'sync_status';

    /** Disco al que el listener de sincronización tiene que mover el fichero. */
    public const string SYNC_TARGET_DISK = 'sync_target_disk';

    /**
     * Todas las versiones del slot, de la más nueva a la más vieja.
     *
     * **Si la relación `media` ya está cargada, no se consulta nada.** Es lo que
     * permite pintar una tabla con el avatar de cada fila sin caer en un N+1:
     * quien lista hace `->with('media')` una vez y aquí se filtra en memoria.
     * Sin la relación cargada se va a la base con el `where` sobre la huella,
     * que es lo correcto cuando se pide un slot suelto.
     *
     * @return Collection<int, Media>
     */
    public static function versions(HasMedia $owner, FileSlotData $slot): Collection
    {
        $media = $owner instanceof Model && $owner->relationLoaded('media')
            ? $owner->getRelation('media')
            : self::query($owner, $slot)->get();

        return $media
            ->filter(static fn (Media $item): bool => $item->collection_name === $slot->collection
                && (string) $item->getCustomProperty(self::FINGERPRINT) === $slot->fingerprint())
            ->sortByDesc(static fn (Media $item): int => self::versionOf($item))
            ->values();
    }

    /**
     * La versión vigente del slot, o `null` si no hay ninguna.
     */
    public static function current(HasMedia $owner, FileSlotData $slot): ?Media
    {
        return self::versions($owner, $slot)
            ->first(static fn (Media $media): bool => (bool) $media->getCustomProperty(self::IS_CURRENT, true));
    }

    /**
     * Consulta base: las filas de `media` que pertenecen a este slot.
     *
     * @return Builder<Media>
     */
    public static function query(HasMedia $owner, FileSlotData $slot): Builder
    {
        return Media::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('collection_name', $slot->collection)
            ->where('custom_properties->'.self::FINGERPRINT, $slot->fingerprint());
    }

    /**
     * Número de versión de una fila. Las filas anteriores al módulo —o escritas
     * por otro camino— cuentan como la 1.
     */
    public static function versionOf(Media $media): int
    {
        return (int) $media->getCustomProperty(self::VERSION, 1);
    }

    /**
     * Traduce la fila al DTO que cruza la frontera.
     *
     * Los dos enums se leen con `tryFrom` y caen a su valor neutro si la fila
     * trae algo desconocido: un archivo con el estado corrupto se sigue pudiendo
     * listar y servir, que es lo que importa.
     */
    public static function toData(Media $media): StoredFileData
    {
        $replacedAt = $media->getCustomProperty(self::REPLACED_AT);
        $createdAt = $media->created_at instanceof CarbonImmutable
            ? $media->created_at
            : CarbonImmutable::parse((string) $media->created_at);

        return new StoredFileData(
            id: (int) $media->getKey(),
            uuid: $media->uuid,
            name: $media->file_name,
            mimeType: $media->mime_type,
            size: (int) $media->size,
            version: self::versionOf($media),
            isCurrent: (bool) $media->getCustomProperty(self::IS_CURRENT, true),
            uploadedBy: self::intOrNull($media->getCustomProperty(self::UPLOADED_BY)),
            compression: FileCompressionStatus::tryFrom((string) $media->getCustomProperty(self::COMPRESSION_STATUS, ''))
                ?? FileCompressionStatus::Pending,
            sync: FileSyncStatus::tryFrom((string) $media->getCustomProperty(self::SYNC_STATUS, ''))
                ?? FileSyncStatus::Local,
            createdAt: $createdAt->toIso8601String(),
            replacedAt: is_string($replacedAt) && $replacedAt !== '' ? $replacedAt : null,
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
