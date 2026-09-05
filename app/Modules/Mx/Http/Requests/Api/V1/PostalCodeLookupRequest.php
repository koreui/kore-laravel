<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;

/**
 * `GET /api/v1/mx/postal-codes/{postalCode}`.
 *
 * Valida un parámetro de **ruta**, que es lo que obliga al `validationData()`
 * de abajo: un `FormRequest` valida por defecto el cuerpo y la query, y sin ese
 * método las reglas se aplicarían sobre un array vacío y no fallaría nunca.
 *
 * La alternativa —`->where('postalCode', '[0-9]{5}')` en la ruta— daría un 404
 * para `/mx/postal-codes/abc`, y eso miente: el recurso no es que no exista, es
 * que la petición está mal escrita. El 422 con `details` le dice al cliente qué
 * arreglar (R54).
 *
 * Extiende `BaseApiRequest` y no `FormRequest`: es lo que convierte el fallo en
 * el 422 del contrato en vez de un redirect 302.
 */
final class PostalCodeLookupRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'postalCode' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
        ];
    }

    /**
     * Lo que se valida es el segmento de la ruta, no el cuerpo.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return ['postalCode' => $this->route('postalCode')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postalCode.regex' => __('El código postal son cinco dígitos.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['postalCode' => __('código postal')];
    }

    /**
     * El código postal ya validado.
     */
    public function postalCode(): string
    {
        return (string) $this->route('postalCode');
    }
}
