<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Modules\Mx\Data\PostalCodeData;
use Illuminate\Http\Request;

/**
 * Un código postal resuelto, tal y como lo publica la API.
 *
 * Envuelve el DTO y no el modelo: el recurso de la API es «un código postal con
 * sus colonias», que en la base son N filas. Publicar `PostalCode` fila a fila
 * obligaría al cliente a agrupar por su cuenta y a fiarse de que el municipio
 * es el mismo en todas.
 *
 * La entidad va anidada (`state: {code, name}`) y no aplanada en dos claves
 * sueltas porque `code` sin `name` no se entiende y `name` sin `code` no sirve
 * para rellenar una factura: son un solo dato con dos caras.
 *
 * @mixin PostalCodeData
 */
final class PostalCodeResource extends BaseApiResource
{
    /**
     * @return array{postal_code: string, state: array{code: string, name: string}, municipality: string, city: string|null, settlements: list<array{name: string, type: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'postal_code' => $this->postalCode,
            'state' => [
                'code' => $this->stateCode,
                'name' => $this->stateName,
            ],
            'municipality' => $this->municipality,
            'city' => $this->city,
            // La lista ya viene con la forma que publica la API: el DTO la
            // guarda como array shape (ver PostalCodeData).
            'settlements' => $this->settlements,
        ];
    }
}
