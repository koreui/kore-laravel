<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Modules\Mx\Data\AmountInWordsData;
use App\Modules\Mx\Http\Requests\Api\V1\AmountInWordsRequest;
use App\Modules\Mx\Http\Resources\Api\V1\AmountInWordsResource;
use App\Modules\Mx\Support\MontoEnLetras;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * El importe en letra, para un cliente que no puede escribirlo él.
 *
 * Existe para que una app móvil o un servicio en otro lenguaje no tengan que
 * reimplementar las reglas de apócope y de centavos —que es donde se cuelan los
 * errores que acaban impresos en un documento—. Un Blade de esta misma
 * aplicación no llama a este endpoint: instancia `MontoEnLetras` y ya está.
 *
 * Público y con `throttle:api`, como el catálogo: no lee nada de nadie, y es una
 * función pura de su parámetro.
 *
 * @see docs/modules/mx.md
 */
#[Group('México')]
final class AmountInWordsController extends ApiController
{
    /**
     * Importe en letra.
     *
     * `GET /api/v1/mx/amount-in-words?amount=1234.56` responde
     * «UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.».
     */
    #[ApiResponse(200, type: AmountInWordsResource::class)]
    public function show(AmountInWordsRequest $request, MontoEnLetras $montoEnLetras): JsonResponse
    {
        $amount = round($request->amount(), 2);

        return $this->respond(AmountInWordsResource::make(new AmountInWordsData(
            amount: $amount,
            words: $montoEnLetras->format($amount),
        )));
    }
}
