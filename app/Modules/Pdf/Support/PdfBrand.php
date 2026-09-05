<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Support;

use App\Core\Data\PdfBrandData;

/**
 * La marca por defecto del boilerplate, armada desde `config/kore-pdf.php`.
 *
 * Vive en el módulo por la misma razón que {@see PdfLogo}: lee configuración
 * del módulo, y un DTO no puede hacerlo (R8, R19). `PdfBrandData` es el dato;
 * esto es quien lo rellena.
 *
 * Un módulo consumidor que necesite otra marca —el logo del cliente de una
 * factura, el pie de una sucursal— construye su propio `PdfBrandData` con los
 * datos que ya tiene a mano; no tiene que pasar por aquí.
 */
final class PdfBrand
{
    /**
     * La marca de la aplicación.
     *
     * @param string|null $documentCode Código de control impreso en cada hoja. Es una propiedad del DOCUMENTO —del formato, de la plantilla—, no de la aplicación, y por eso lo pone quien lo genera y no la configuración.
     * @param bool $withWatermark Si esta copia va sellada. La marca de agua **se pide**: el mismo documento se descarga limpio para entregarlo y sellado para que circule internamente.
     */
    public static function default(?string $documentCode = null, bool $withWatermark = false): PdfBrandData
    {
        return new PdfBrandData(
            logoUrl: PdfLogo::embedded(),
            secondaryLogoUrl: PdfLogo::secondaryEmbedded(),
            footerLines: self::footerLines(),
            documentCode: $documentCode,
            watermark: self::watermark($withWatermark),
        );
    }

    /**
     * Las líneas del pie, ya limpias.
     *
     * Se filtran las vacías porque una línea en blanco en el pie no se ve como
     * un error de configuración: se ve como un pie mal alineado.
     *
     * @return list<string>
     */
    private static function footerLines(): array
    {
        /** @var array<int, mixed> $lines */
        $lines = (array) config('kore-pdf.footer_lines', []);

        return array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * El texto de la marca de agua, o `null` si esta copia no la lleva.
     *
     * La decisión está aquí y no en cada sitio que arma una marca porque la
     * regla es una sola: sin pedirla, no hay marca de agua; y pidiéndola sin
     * texto configurado, tampoco (mejor sin sello que con la palabra vacía).
     */
    private static function watermark(bool $withWatermark): ?string
    {
        if (! $withWatermark) {
            return null;
        }

        $text = trim((string) config('kore-pdf.watermark', ''));

        return $text === '' ? null : $text;
    }
}
