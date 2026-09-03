<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base de todo API Resource del boilerplate (R54).
 *
 * Su única responsabilidad es fijar el envelope: la representación de un
 * recurso viaja **siempre** dentro de `data`, sola o acompañada de `meta`.
 *
 * ```json
 * { "data": { "id": 1, "name": "Ada" } }
 * { "data": [ … ], "meta": { "next_cursor": "…", "per_page": 25 } }
 * ```
 *
 * Redeclarar `$wrap` aquí no es decorativo aunque el valor coincida con el de
 * `JsonResource`: `JsonResource::withoutWrapping()` escribe sobre la propiedad
 * de la clase padre, y con la propia el envelope de la API sobrevive a que otro
 * paquete o una vista decida desenvolver sus resources.
 *
 * Un resource concreto declara qué campos publica y nada más:
 *
 * ```php
 * final class DeviceResource extends BaseApiResource
 * {
 *     public function toArray(Request $request): array
 *     {
 *         return ['id' => $this->uuid, 'name' => $this->name];
 *     }
 * }
 * ```
 *
 * Ver `docs/guides/api.md`.
 */
abstract class BaseApiResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = 'data';
}
