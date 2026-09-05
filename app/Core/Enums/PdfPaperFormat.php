<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * El tamaño de papel de un documento PDF.
 *
 * Tres casos y no los once de `Spatie\LaravelPdf\Enums\Format`: son los que
 * usan de verdad los documentos que emite una aplicación de gestión, y cada
 * uno hay que probarlo en la hoja (los márgenes y la caja del pie cambian).
 * Añadir uno es una línea aquí y una revisión de `pdf::layouts.base`.
 *
 * Vive en `Core` y no en el módulo Pdf porque viaja dentro de
 * `App\Core\Data\PdfOptionsData`, que es lo que un módulo consumidor le pasa al
 * contrato `App\Core\Contracts\PdfRenderer` (R5: nadie importa del módulo Pdf).
 *
 * El `value` es el que entiende spatie/laravel-pdf, en minúsculas.
 */
enum PdfPaperFormat: string
{
    case A4 = 'a4';

    case Letter = 'letter';

    case Legal = 'legal';

    /**
     * El nombre tal y como se escribe en `size:` de una regla `@page`.
     */
    public function cssSize(): string
    {
        return match ($this) {
            self::A4 => 'A4',
            self::Letter => 'Letter',
            self::Legal => 'Legal',
        };
    }
}
