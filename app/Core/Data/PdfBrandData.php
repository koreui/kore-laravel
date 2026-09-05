<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * La marca con la que sale una hoja que va a acabar en PDF: qué logos lleva,
 * qué dice el pie, qué código va impreso en cada página y si va sellada.
 *
 * **Todo son primitivos a propósito.** La hoja la escribe un módulo y la marca
 * la arma otro —el logo puede salir del cliente, del proyecto o de la
 * configuración—, así que pasar un modelo sería importar entre módulos, que es
 * justo lo que R5 prohíbe. Quien arma esto es el dueño del documento; la hoja
 * sólo recibe el DTO.
 *
 * Los logos llegan ya embebidos como `data:` URI, no como URL —
 * {@see \App\Core\Support\PdfImage} explica por qué—.
 *
 * **Aquí no se lee configuración.** R19 y el arch test de R8 dicen que un DTO
 * sólo depende de datos: nada de `config()`, nada de `public_path()`. La marca
 * por defecto del boilerplate la arma `App\Modules\Pdf\Support\PdfBrand`, que
 * sí puede leer `config/kore-pdf.php` porque vive en el módulo.
 */
final class PdfBrandData extends Data
{
    /**
     * @param string|null $logoUrl `data:` URI del logo principal, si lo hay.
     * @param string|null $secondaryLogoUrl `data:` URI de un segundo logo (el del cliente, el de una marca blanca…).
     * @param list<string> $footerLines Líneas del pie de página. Vacío = sin pie.
     * @param string|null $documentCode Código de control impreso en cada hoja (ej. «KORE-PDF-001»).
     * @param string|null $watermark Texto de la marca de agua diagonal. `null` = sin marca de agua.
     */
    public function __construct(
        public readonly ?string $logoUrl = null,
        public readonly ?string $secondaryLogoUrl = null,
        public readonly array $footerLines = [],
        public readonly ?string $documentCode = null,
        public readonly ?string $watermark = null,
    ) {}

    /**
     * Si la hoja tiene pie que pintar.
     *
     * Existe para que la Blade no tenga que saber que «sin pie» se representa
     * con un array vacío.
     */
    public function hasFooter(): bool
    {
        return $this->footerLines !== [];
    }
}
