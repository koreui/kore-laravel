<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * En qué punto está la compresión de un archivo guardado por `FileStore`.
 *
 * Vive en `Core` y no en el módulo Files por lo mismo que `SystemRole`: quien
 * pinta el estado de un archivo —una tabla, un resource de la API— tiene que
 * poder compararlo sin importar `App\Modules\Files\*` (R5, R6).
 *
 * Los cuatro casos son un ciclo, no una escala de calidad:
 *
 * - `Pending`: el archivo está guardado y la compresión aún no ha corrido. Es
 *   el estado con el que nace **todo** archivo, también cuando la compresión
 *   está apagada: así una instalación que la encienda mañana sabe qué le falta.
 * - `Done`: corrió y el archivo de disco es el resultado (o el original, si
 *   comprimirlo lo dejaba más grande).
 * - `Failed`: corrió y no pudo. El archivo original sigue intacto y servible:
 *   fallar comprimiendo nunca cuesta el documento.
 * - `Skipped`: no había nada que comprimir (un `.docx`, un `.zip`) o la
 *   compresión está apagada por configuración.
 */
enum FileCompressionStatus: string
{
    case Pending = 'pending';

    case Done = 'done';

    case Failed = 'failed';

    case Skipped = 'skipped';

    /**
     * Etiqueta legible para una tabla o un badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Done => 'Comprimido',
            self::Failed => 'Falló',
            self::Skipped => 'Sin comprimir',
        };
    }
}
