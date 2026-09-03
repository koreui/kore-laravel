<?php

declare(strict_types=1);

namespace App\Modules\Auth\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Adaptador de Fortify, NO una Action del boilerplate.
 *
 * Fortify fija el nombre de la clase y la firma del método (`update()`) a
 * través de su contrato, así que estos stubs no pueden cumplir la regla 1 de
 * CLAUDE.md (sufijo `Action`, método `handle()`). Por eso viven en
 * `App\Modules\Auth\Fortify\` y no en `Actions/`: esa carpeta es sólo para
 * casos de uso propios.
 *
 * La lógica de negocio de verdad va en una Action del módulo; el adaptador se
 * limita a validar la entrada de Fortify y delegar.
 */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
