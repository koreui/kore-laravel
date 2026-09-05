<?php

declare(strict_types=1);

namespace App\Modules\Files\Listeners;

use App\Core\Enums\FileSyncStatus;
use App\Modules\Files\Actions\FileSyncAction;
use App\Modules\Files\Events\FileStored;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Sube en cola el archivo del disco de staging a su disco de destino.
 *
 * Sólo se registra con `files.sync.enabled` en true, y **sólo si la compresión
 * está apagada**: cuando las dos están encendidas es `CompressStoredFile` quien
 * encadena la subida al terminar, porque comprimir cambia el fichero y subirlo
 * antes significaría subirlo dos veces. Ese reparto lo decide el provider, y por
 * eso los dos listeners nunca corren sobre el mismo archivo.
 *
 * Los reintentos son largos a propósito: un bucket que no responde suele volver,
 * y mientras tanto el fichero se sigue sirviendo desde el disco local. Agotarlos
 * deja la fila en `failed` —visible— con el fichero intacto.
 */
/*
 * Cinco intentos y cinco minutos: subir a un bucket es red, y la red vuelve.
 * El techo alto cubre el fichero grande con la conexión lenta; los intentos, la
 * incidencia del proveedor. Ver `backoff()`.
 */
#[Timeout(300)]
#[Tries(5)]
final class SyncStoredFile implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly FileSyncAction $syncFile,
    ) {}

    /**
     * Espera creciente entre intentos, en segundos: medio minuto, un minuto,
     * cinco, quince, media hora. Cubre desde el corte de red de un segundo
     * hasta la incidencia del proveedor.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 1800];
    }

    public function handle(FileStored $event): void
    {
        if ($this->syncFile->handle($event->fileId) === FileSyncStatus::Failed) {
            // Se relanza para que la cola aplique el backoff. La Action ya dejó
            // la fila en `failed`, así que si se agotan los intentos el estado
            // que queda escrito es el correcto.
            throw new RuntimeException("No se pudo sincronizar el archivo #{$event->fileId}.");
        }
    }

    public function failed(FileStored $event, Throwable $exception): void
    {
        $media = Media::find($event->fileId);

        $media?->setCustomProperty(MediaSlots::SYNC_STATUS, FileSyncStatus::Failed->value);
        $media?->save();
    }
}
