<?php

declare(strict_types=1);

namespace App\Modules\Files\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Files\Actions\FileDeleteAction;
use App\Modules\Files\Support\MediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Purga las versiones archivadas hace más de N días.
 *
 * El módulo no borra nada por su cuenta: reemplazar un archivo lo archiva y lo
 * conserva, que es lo que permite responder «¿qué documento había aquí en
 * marzo?». Pasado el plazo eso deja de ser historial y pasa a ser un fichero
 * guardado sin motivo —y ocupando disco—, y este comando es donde se decide
 * cuándo.
 *
 * Tres condiciones para borrar, y las tres a la vez:
 *
 * 1. `is_current = false` — la versión vigente de un slot no se toca **nunca**,
 *    tenga la edad que tenga.
 * 2. `replaced_at` presente y anterior al corte. Sin fecha no se borra: una
 *    versión archivada sin marca de tiempo es un dato incompleto, y ante la duda
 *    se conserva.
 * 3. El plazo lo pone `--days`, no el config: purgar es destructivo y la cifra
 *    se escribe donde se ve, en la línea del scheduler.
 *
 * `--dry-run` (`App\Core\Console\Concerns\SupportsDryRun`) cuenta exactamente lo
 * mismo sin escribir: es lo que se corre la primera vez en producción.
 *
 * El borrado va por `FileDeleteAction`, que es quien sabe que eliminar la fila
 * se lleva también el fichero del disco (lo hace el observer del paquete).
 */
#[Description('Borra las versiones archivadas de archivos reemplazadas hace más de N días')]
#[Signature('files:cleanup {--days=30 : Días desde que la versión dejó de ser la vigente}')]
final class FilesCleanupCommand extends Command
{
    use SupportsDryRun;

    public function handle(FileDeleteAction $deleteFile): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $ids = $this->prunableIds($cutoff);

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se borrarían %d versión(es) archivada(s) antes de %s, con sus ficheros.',
                count($ids),
                $cutoff->toDateString(),
            ));

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            $deleteFile->handle($id);
        }

        $this->components->info(sprintf(
            'files:cleanup — %d versión(es) archivada(s) borrada(s).',
            count($ids),
        ));

        return self::SUCCESS;
    }

    /**
     * Ids de las versiones purgables.
     *
     * El filtro por fecha se hace en PHP y no con un `where` sobre el JSON: la
     * `replaced_at` se guarda como ISO 8601 dentro de `custom_properties`, y
     * comparar fechas dentro de un JSON no se escribe igual en SQLite, MySQL y
     * Postgres. El conjunto que llega aquí ya está acotado —sólo archivos con
     * `replaced_at`, que son los archivados—, así que la comparación en memoria
     * no es el cuello de botella; si algún día lo fuera, la salida es una
     * columna de verdad, no un `whereRaw` por motor.
     *
     * @return list<int>
     */
    private function prunableIds(CarbonImmutable $cutoff): array
    {
        return array_values(Media::query()
            ->whereNotNull('custom_properties->'.MediaSlots::REPLACED_AT)
            ->get()
            ->filter(static function (Media $media) use ($cutoff): bool {
                if ((bool) $media->getCustomProperty(MediaSlots::IS_CURRENT, true)) {
                    return false;
                }

                $replacedAt = $media->getCustomProperty(MediaSlots::REPLACED_AT);

                return is_string($replacedAt)
                    && $replacedAt !== ''
                    && CarbonImmutable::parse($replacedAt)->lessThan($cutoff);
            })
            ->map(static fn (Media $media): int => (int) $media->getKey())
            ->all());
    }
}
