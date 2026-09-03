<?php

declare(strict_types=1);

namespace App\Modules\Auth\Fortify;

use App\Models\User;
use App\Modules\Auth\Actions\AuthUserRegisterAction;
use App\Modules\Auth\Data\RegisterData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Adaptador de Fortify, NO una Action del boilerplate.
 *
 * Fortify fija el nombre de la clase y la firma del método (`create()`) a
 * través de su contrato, así que estos stubs no pueden cumplir la regla 1 de
 * CLAUDE.md (sufijo `Action`, método `handle()`). Por eso viven en
 * `App\Modules\Auth\Fortify\` y no en `Actions/`: esa carpeta es sólo para
 * casos de uso propios.
 *
 * La lógica de negocio de verdad va en una Action del módulo; el adaptador se
 * limita a validar la entrada de Fortify y delegar.
 */
final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private AuthUserRegisterAction $registerUser,
    ) {}

    /**
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->registerUser->handle(new RegisterData(
            name: $input['name'],
            email: $input['email'],
            password: $input['password'],
        ));
    }
}
