<?php

declare(strict_types=1);

namespace App\Modules\Mx\Data;

use App\Core\Data\Data;

/**
 * Un código postal mexicano resuelto: sus colonias, su municipio y su entidad.
 *
 * Es lo que devuelve `App\Modules\Mx\Support\PostalCodes::lookup()` y lo que va
 * a la caché, así que es también la forma en la que el catálogo cruza la
 * frontera del módulo: quien lo consume no toca los modelos ni la tabla.
 *
 * La lista de colonias nunca está vacía —un código postal sin asentamientos no
 * existe en el catálogo, y `lookup()` devuelve `null` en ese caso— y llega
 * ordenada por nombre, que es como se pinta en un desplegable.
 *
 * **Por qué las colonias son arrays y no un `SettlementData`.** Lo natural sería
 * un DTO anidado, pero R8 tiene la forma de una lista blanca: un DTO sólo puede
 * depender de `App\Core\Data`, `App\Core\Enums`, `Spatie\LaravelData` y enums,
 * y eso deja fuera al DTO de al lado en el mismo módulo. Antes que abrir una
 * válvula, la lista viaja como una *array shape* documentada, que es la única
 * parte de este archivo que un cambio en R8 haría innecesaria (ver el informe
 * de la v2.4).
 */
final class PostalCodeData extends Data
{
    /**
     * @param list<array{name: string, type: string}> $settlements colonias del
     *                                                             código postal,
     *                                                             ordenadas por
     *                                                             nombre
     */
    public function __construct(
        public readonly string $postalCode,
        public readonly string $stateCode,
        public readonly string $stateName,
        public readonly string $municipality,
        public readonly ?string $city,
        public readonly array $settlements,
    ) {}
}
