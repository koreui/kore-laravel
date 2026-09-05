<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Support;

use App\Core\Data\PdfBrandData;

/**
 * Los datos de la hoja de ejemplo (`pdf::examples.sample`).
 *
 * Existen para que el controller de la vista previa no tenga que armarlos (R4)
 * y para que la versión HTML y la versión PDF partan exactamente de los mismos
 * datos: si cada una construyera los suyos, la comparación entre pantalla y
 * papel dejaría de probar nada.
 *
 * Es una pieza de demostración, no de dominio: un módulo real arma sus datos en
 * su propia Action y no pasa por aquí.
 */
final class PdfSample
{
    /** El código impreso en cada hoja del ejemplo. */
    private const string DOCUMENT_CODE = 'KORE-PDF-001';

    /**
     * Todo lo que la hoja de ejemplo necesita para pintarse.
     *
     * @return array{title: string, brand: PdfBrandData, fields: array<string, string>, rows: list<array{concept: string, quantity: string, amount: string}>}
     */
    public static function sheet(bool $withWatermark = false): array
    {
        return [
            'title' => __('Documento de ejemplo'),
            'brand' => PdfBrand::default(self::DOCUMENT_CODE, $withWatermark),
            'fields' => [
                __('Folio') => 'KORE-2026-000123',
                __('Fecha') => now()->toDateString(),
                __('Emitido por') => config('app.name'),
                __('Formato') => mb_strtoupper((string) config('kore-pdf.format', 'a4')),
            ],
            'rows' => [
                ['concept' => __('Licencia anual'), 'quantity' => '1', 'amount' => '12,000.00'],
                ['concept' => __('Horas de soporte'), 'quantity' => '24', 'amount' => '18,000.00'],
                ['concept' => __('Puesta en marcha'), 'quantity' => '1', 'amount' => '30,000.00'],
            ],
        ];
    }
}
