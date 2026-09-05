<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;

/**
 * Un archivo ya guardado, tal y como sale de `App\Core\Contracts\FileStore`.
 *
 * Es la frontera: quien consume archivos —un componente Livewire, un resource
 * de la API, una vista— habla de esto y nunca del modelo `Media` de
 * media-library. Así el módulo Files puede cambiar de paquete por debajo sin
 * arrastrar a nadie, y sobre todo: R30 se cumple sola, porque lo que llega a
 * una Blade ya es un dato y no una fila de Eloquent con relaciones colgando.
 *
 * `version` e `isCurrent` son las dos caras del versionado por slot: sólo una
 * versión de un slot es la vigente, y las demás siguen existiendo con su
 * `replacedAt` puesto. Un archivo **nunca** se borra al reemplazarlo.
 *
 * Las dos fechas viajan **ya formateadas en ISO 8601**, no como
 * `CarbonImmutable`, por lo mismo que el `expiresAt` de
 * `App\Modules\Auth\Data\ApiTokenData`: PHPat comprueba que un DTO sólo dependa
 * de datos, de enums de `Core` y de `spatie/laravel-data` (R8), y `Carbon` es un
 * colaborador de vendor como cualquier otro. Quien las consume —un resource,
 * una vista, un `<time datetime>`— lo único que iba a hacer con ellas era
 * formatearlas; quien necesite aritmética de fechas trabaja sobre el modelo, en
 * una Action.
 */
final class StoredFileData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $uuid,
        public readonly string $name,
        public readonly ?string $mimeType,
        public readonly int $size,
        public readonly int $version,
        public readonly bool $isCurrent,
        public readonly ?int $uploadedBy,
        public readonly FileCompressionStatus $compression,
        public readonly FileSyncStatus $sync,
        public readonly string $createdAt,
        public readonly ?string $replacedAt,
    ) {}

    /**
     * ¿Se puede pintar como imagen?
     *
     * Lo pregunta el componente de subida para decidir entre una miniatura y el
     * icono genérico de fichero. Se mira el mime y no la extensión: la
     * extensión la elige quien sube el archivo y el mime lo determina el
     * servidor al recibirlo.
     */
    public function isImage(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/');
    }
}
