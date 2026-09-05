<?php

declare(strict_types=1);

namespace App\Modules\Files\Events;

/**
 * Un archivo acaba de guardarse: el fichero está en disco y la fila escrita.
 *
 * Es la frontera pública del módulo (R5). Los dos listeners del propio Files
 * —comprimir y sincronizar— lo escuchan, y cualquier otro módulo puede hacer lo
 * mismo sin importar nada más de `App\Modules\Files`: antivirus, extracción de
 * texto, marca de agua, aviso a quien tenga que revisarlo.
 *
 * Viajan el id y el mime, no el `Media` ni el `StoredFileData`: un listener en
 * cola se serializa, y lo que se guarda en la cola tiene que ser lo mínimo que
 * permita volver a mirar la base cuando el job por fin corra. Entre el disparo y
 * la ejecución el archivo puede haber cambiado de disco (la sincronización) o de
 * tamaño (la compresión), así que quedarse con una copia sería quedarse con una
 * foto vieja.
 *
 * El mime va aparte porque es lo que decide **qué** hacer con el archivo, y
 * decidirlo sin volver a la base ahorra la consulta al listener que no aplica.
 */
final readonly class FileStored
{
    public function __construct(
        public int $fileId,
        public ?string $mimeType,
    ) {}
}
