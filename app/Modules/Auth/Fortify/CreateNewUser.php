<?php

declare(strict_types=1);

namespace App\Modules\Auth\Fortify;

use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Auth\Actions\AuthUserRegisterAction;
use App\Modules\Auth\Actions\InvitationRedeemAction;
use App\Modules\Auth\Data\RegisterData;
use App\Modules\Auth\Rules\ValidInvitationCode;
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
 * limita a validar la entrada de Fortify y delegar. Cuál de las dos Actions se
 * lleva el trabajo lo decide `AUTH_INVITATIONS`: con el toggle apagado el alta
 * es la de siempre, y con él encendido pasa por el canje del código.
 */
final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private AuthUserRegisterAction $registerUser,
        private InvitationRedeemAction $redeemInvitation,
    ) {}

    /**
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $invitationsEnabled = (bool) config('kore-app.auth.invitations');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ];

        if ($invitationsEnabled) {
            $rules['invitation_code'] = ['required', 'string', 'max:32', new ValidInvitationCode];
        }

        Validator::make($input, $rules, attributes: [
            'invitation_code' => __('código de invitación'),
        ])->validate();

        $data = new RegisterData(
            name: $input['name'],
            email: $input['email'],
            password: $input['password'],
            invitationCode: $invitationsEnabled ? $input['invitation_code'] : null,
        );

        if (! $invitationsEnabled || $data->invitationCode === null) {
            return $this->registerUser->handle($data);
        }

        /*
         * El canje vuelve a comprobar el código con la fila bloqueada, así que
         * puede rechazar lo que la validación acababa de aceptar: entre las dos
         * cosas cabe otro registro que agotó el cupo. Eso llega aquí como
         * `ConflictException` y se devuelve como lo que es para quien está
         * delante del formulario — un error del campo, no un 500.
         */
        try {
            return $this->redeemInvitation->handle($data, $data->invitationCode);
        } catch (ConflictException $e) {
            throw ValidationException::withMessages([
                'invitation_code' => $e->getMessage(),
            ]);
        }
    }
}
