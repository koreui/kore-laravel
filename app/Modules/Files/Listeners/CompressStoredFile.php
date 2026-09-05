<?php

declare(strict_types=1);

namespace App\Modules\Files\Listeners;

use App\Modules\Files\Actions\FileCompressAction;
use App\Modules\Files\Actions\FileSyncAction;
use App\Modules\Files\Events\FileStored;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Comprime en cola el archivo recién guardado.
 *
 * Sólo se registra con `files.compression.enabled` en true: si la compresión
 * está apagada, este listener no existe y todo archivo se queda en `pending`,
 * que es la verdad —nadie ha intentado comprimirlo—.
 *
 * **R3 · por qué un listener y no un job.** La lista de carpetas de un módulo es
 * cerrada y no incluye `Jobs/`. El trabajo en cola del boilerplate se modela
 * como un listener `ShouldQueue` que reacciona a un evento del módulo y delega
 * en una Action. Se pierde poco —`dispatch()` a mano— y se gana que el trabajo
 * asíncrono tenga siempre un disparador con nombre en `Events/`, que es lo que
 * hace que otro módulo pueda engancharse sin tocar Files.
 *
 * **Encadena la sincronización al terminar**, como en Notarium: comprimir
 * cambia el fichero, así que subirlo antes sería subir el original y volver a
 * subir el comprimido. Y se encadena pase lo que pase —también si la compresión
 * falla—, porque el archivo tiene que acabar en su disco aunque no se haya
 * podido optimizar.
 */
/*
 * Dos intentos: el primero cubre el temporal que se quedó a medias, y más allá
 * de eso el problema es el contenido del fichero, no la suerte. Dos minutos de
 * techo, que es lo que tarda Ghostscript con un escaneo grande.
 */
#[Timeout(120)]
#[Tries(2)]
final class CompressStoredFile implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly FileCompressAction $compressFile,
        private readonly FileSyncAction $syncFile,
    ) {}

    public function handle(FileStored $event): void
    {
        try {
            $this->compressFile->handle($event->fileId);
        } finally {
            $this->syncIfEnabled($event->fileId);
        }
    }

    /**
     * Si el listener muere del todo, el archivo sigue teniendo que llegar a su
     * disco. `failed()` es la última oportunidad de que eso pase.
     */
    public function failed(FileStored $event, Throwable $exception): void
    {
        $this->syncIfEnabled($event->fileId);
    }

    private function syncIfEnabled(int $fileId): void
    {
        if ((bool) config('files.sync.enabled', false)) {
            $this->syncFile->handle($fileId);
        }
    }
}
