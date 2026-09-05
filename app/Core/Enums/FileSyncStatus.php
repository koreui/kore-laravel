<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Dónde está de verdad el fichero de un archivo guardado por `FileStore`.
 *
 * Existe porque subir a un disco remoto es E/S de red y la petición que sube el
 * archivo no puede depender de ella: el archivo se guarda primero en un disco
 * local (staging) y un listener en cola lo mueve después. Entre esos dos
 * momentos hay una ventana en la que el fichero existe, es servible y **no**
 * está donde acabará, y este enum es lo que la hace visible.
 *
 * - `Local`: el fichero está en el disco de la propia máquina. Lo sirve la ruta
 *   firmada `files.serve` por stream.
 * - `Synced`: está en el disco de destino. La ruta firmada redirige a la URL
 *   temporal del disco (S3/R2) en vez de servirlo.
 * - `Failed`: la subida falló después de agotar los reintentos. El fichero
 *   local sigue ahí y sigue sirviéndose: un fallo de sincronización degrada el
 *   coste, nunca el acceso.
 *
 * Con `files.sync.enabled` en `false` —el caso por defecto— todo archivo nace y
 * muere en `Local`, que es exactamente lo que un proyecto sin S3 necesita.
 */
enum FileSyncStatus: string
{
    case Local = 'local';

    case Synced = 'synced';

    case Failed = 'failed';

    /**
     * Etiqueta legible para una tabla o un badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Local => 'En el servidor',
            self::Synced => 'Sincronizado',
            self::Failed => 'Falló la sincronización',
        };
    }
}
