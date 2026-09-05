<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Un PDF ya generado: su nombre de archivo y sus bytes.
 *
 * Es lo que devuelve `App\Core\Contracts\PdfRenderer`. El contenido viaja en
 * memoria y no como ruta en disco porque la mayoría de los documentos del
 * boilerplate se generan al vuelo y no se guardan: los datos ya están en la
 * base, y un archivo almacenado sólo sería una copia que mantener
 * sincronizada. Quien quiera persistirlo escribe `$contents` donde le convenga.
 *
 * **No sabe construir una respuesta HTTP a propósito.** Un `toDownloadResponse()`
 * aquí ataría `App\Core\Data` a `Illuminate\Http` y rompería R8 (un DTO
 * transporta datos, no fabrica objetos de la capa de entrega). Quien monta la
 * `Response` es el controller, que ya está en Http y es quien sabe si el
 * documento se abre en el visor o se descarga.
 */
final class PdfDocumentData extends Data
{
    /**
     * @param string $filename Nombre del archivo, con su extensión.
     * @param string $contents Los bytes del PDF. Binario, no base64.
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
    ) {}

    /**
     * El peso del documento en bytes.
     *
     * `strlen` y no `mb_strlen`: se cuentan bytes, no caracteres.
     */
    public function size(): int
    {
        return strlen($this->contents);
    }
}
