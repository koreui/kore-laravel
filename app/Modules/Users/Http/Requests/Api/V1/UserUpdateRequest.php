<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests\Api\V1;

/**
 * `PUT`/`PATCH /api/v1/users/{user}`.
 *
 * `password` es `nullable`: omitirla significa «no la cambies», que es lo mismo
 * que significa dejar el campo en blanco en el formulario y lo que espera
 * `UserUpdateAction`.
 *
 * El verbo `PATCH` comparte reglas con `PUT` a propósito. Un PATCH que valida
 * sólo lo que llega suena bien hasta que alguien manda `{"role": "…"}` a secas
 * y el `GrantableRole` se evalúa contra un usuario del que no sabemos el resto:
 * aquí el cuerpo describe el usuario entero en los dos casos, y el cliente que
 * quiera tocar un campo manda los demás como estaban.
 */
final class UserUpdateRequest extends UserApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->sharedRules(),
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }
}
