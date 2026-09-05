<?php

declare(strict_types=1);

namespace App\Core\Data;

use App\Core\Enums\PdfPaperFormat;

/**
 * Cómo se imprime un documento: nombre del archivo, tamaño de papel,
 * orientación y márgenes.
 *
 * Es lo que un módulo consumidor le pasa a `App\Core\Contracts\PdfRenderer`
 * junto a la vista y sus datos. No lleva la marca (eso es
 * {@see PdfBrandData}) ni el contenido: sólo la mecánica de la impresión.
 *
 * **`null` significa «lo que diga la configuración», no «nada».** Un documento
 * que no opina sobre el papel ni sobre los márgenes hereda los de
 * `config/kore-pdf.php`, y quien los resuelve es el renderer —que vive en el
 * módulo y sí puede leer config—. Si `format` y `margins` tuvieran aquí un
 * valor por defecto, cambiar el papel de toda la aplicación dejaría de ser una
 * variable de entorno y pasaría a ser un `grep`.
 */
final class PdfOptionsData extends Data
{
    /**
     * @param string $filename Nombre del archivo que se descarga. Sin `.pdf` se lo añade el builder.
     * @param PdfPaperFormat|null $format Tamaño de papel. `null` = el de `kore-pdf.format`.
     * @param bool $landscape Apaisado. Por defecto vertical, que es como se lee un documento.
     * @param array{top: float, right: float, bottom: float, left: float}|null $margins Márgenes en milímetros. `null` = los de `kore-pdf.margins`.
     */
    public function __construct(
        public readonly string $filename,
        public readonly ?PdfPaperFormat $format = null,
        public readonly bool $landscape = false,
        public readonly ?array $margins = null,
    ) {}
}
