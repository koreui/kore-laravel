<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;

/**
 * `GET /api/v1/mx/amount-in-words?amount=1234.56`.
 *
 * Sólo `amount`. La moneda y el sufijo son parámetros de `MontoEnLetras`, pero
 * **no** de este endpoint: son texto libre que saldría literal en la respuesta,
 * y un endpoint público que devuelve lo que le mandan es un espejo cómodo para
 * quien quiera hacer pasar por nuestra una cadena suya. Quien necesite otra
 * divisa llama a la clase desde su propio código.
 *
 * El máximo es el mismo que sabe nombrar `MontoEnLetras`; por encima, la clase
 * lanzaría y el cliente recibiría un 500 donde lo correcto es un 422.
 */
final class AmountInWordsRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['amount' => __('importe')];
    }

    /**
     * El importe validado.
     */
    public function amount(): float
    {
        return (float) $this->validated('amount');
    }
}
