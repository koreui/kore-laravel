<?php

declare(strict_types=1);

namespace App\Modules\Auth\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

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
final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        if ($input['email'] !== $user->email) {
            $this->updateVerifiedUser($user, $input);

            return;
        }

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
        ])->save();
    }

    /**
     * @param array<string, string> $input
     */
    private function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
