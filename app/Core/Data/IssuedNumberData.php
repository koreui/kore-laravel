<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * Un folio ya emitido, tal y como sale de `App\Core\Contracts\NumberSeries`.
 *
 * Viajan las dos caras del número y no una sola, porque las dos hacen falta y
 * derivar una de la otra es donde se pierden los datos:
 *
 * - `number` es el entero del contador. Es lo que se ordena, lo que se compara
 *   y con lo que se detecta un hueco en una auditoría.
 * - `formatted` es lo que ve una persona (`REC-2026-000123`). Lleva dentro el
 *   prefijo y el año, así que **no** se puede volver a convertir en entero sin
 *   conocer el formato con el que se generó — y el formato de una serie puede
 *   cambiar de un año para otro.
 *
 * Guarda los dos en la fila del documento: el entero para el índice y las
 * consultas, el texto para imprimirlo. Es lo mismo que hacen `folio_numero` y
 * `folio` en la tabla `recibos` de Notarium.
 *
 * `issuedAt` viaja **ya formateada en ISO 8601**, no como `CarbonImmutable`, por
 * lo mismo que las fechas de `StoredFileData`: PHPat comprueba que un DTO sólo
 * dependa de datos, de enums de `Core` y de `spatie/laravel-data` (R8), y
 * `Carbon` es un colaborador de vendor como cualquier otro.
 */
final class IssuedNumberData extends Data
{
    public function __construct(
        public readonly string $series,
        public readonly ?string $scope,
        public readonly int $number,
        public readonly string $formatted,
        public readonly string $issuedAt,
    ) {}
}
