<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests\Api\V1;

/**
 * `POST /api/v1/users`.
 *
 * La contraseña es obligatoria y va `confirmed`, igual que en el formulario:
 * una API que acepta `password` sin confirmar y un formulario que la exige son
 * dos definiciones distintas de la misma cuenta, y la que se relaja es siempre
 * la que nadie mira.
 */
final class UserStoreRequest extends UserApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->sharedRules(),
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
