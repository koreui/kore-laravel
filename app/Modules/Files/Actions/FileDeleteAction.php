<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Core\Actions\Action;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Borra el archivo y su fichero. De verdad y sin vuelta atrás.
 *
 * Es la hermana irreversible de `FileArchiveAction`, y por eso no la llama
 * ningún botón: la usan el dueño cuando se borra a sí mismo (media-library ya
 * limpia sus medias al borrar el modelo, pero un dueño que sólo quiere soltar
 * un archivo pasa por aquí) y `files:cleanup`, que purga las versiones
 * archivadas hace más del plazo de retención.
 *
 * El borrado del fichero en disco lo hace el observer del paquete al eliminar la
 * fila (`MediaObserver` → `FileRemover`), así que no hay que tocar `Storage`:
 * hacerlo a mano abriría la puerta a borrar el fichero y dejar la fila, que es
 * el peor de los dos estados.
 *
 * Idempotente: un id que ya no existe no es un error.
 */
final class FileDeleteAction extends Action
{
    public function handle(int $fileId): void
    {
        Media::find($fileId)?->delete();
    }
}
