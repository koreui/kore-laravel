<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Modules\Mx\Data\AmountInWordsData;
use Illuminate\Http\Request;

/**
 * Un importe y su forma en letra.
 *
 * Devuelve también el `amount` **ya redondeado**, que es el que se convirtió: un
 * cliente que mande `1234.567` tiene que poder ver que la letra dice 57
 * centavos porque el importe se redondeó a `1234.57`, y no quedarse comparando
 * su número con una cadena.
 *
 * @mixin AmountInWordsData
 */
final class AmountInWordsResource extends BaseApiResource
{
    /**
     * @return array{amount: float, words: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'amount' => $this->amount,
            'words' => $this->words,
        ];
    }
}
