<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Core\Actions\Action;
use App\Modules\Files\Support\MediaSlots;
use Carbon\CarbonImmutable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Archiva un archivo: deja de ser el vigente y el fichero se queda.
 *
 * Es lo que ocurre cuando alguien pulsa «eliminar» en la interfaz, y el nombre
 * distinto no es cosmética. Un archivo suele ser el soporte de algo que pasó
 * —un contrato firmado, un justificante, la foto que se usó en un informe—, así
 * que quitarlo de la vista y destruirlo son dos decisiones muy distintas y sólo
 * una es reversible. Aquí se toma la reversible: `is_current = false`,
 * `replaced_at` con la hora, y el historial lo sigue enseñando.
 *
 * **Idempotente**: si ya estaba archivado no se toca nada, y en particular no se
 * mueve la marca de tiempo. Es la que dice cuándo dejó de valer, y reescribirla
 * cada vez que alguien vuelva a pulsar el botón la convertiría en «la última vez
 * que alguien miró».
 *
 * Un id que no existe tampoco es un error: archivar lo que ya no está es el
 * resultado que se pedía.
 */
final class FileArchiveAction extends Action
{
    public function handle(int $fileId): void
    {
        $media = Media::find($fileId);

        if (! $media instanceof Media) {
            return;
        }

        if (! (bool) $media->getCustomProperty(MediaSlots::IS_CURRENT, true)) {
            return;
        }

        $media->setCustomProperty(MediaSlots::IS_CURRENT, false);
        $media->setCustomProperty(MediaSlots::REPLACED_AT, CarbonImmutable::now()->toIso8601String());
        $media->save();
    }
}
