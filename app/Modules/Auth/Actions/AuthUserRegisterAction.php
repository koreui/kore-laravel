<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Auth\Data\RegisterData;
use Illuminate\Support\Facades\Hash;

/**
 * Registro público de una cuenta.
 *
 * A diferencia del alta desde el panel (`Users\Actions\UserCreateAction`), aquí
 * el usuario NO queda verificado: Fortify dispara `Registered` y el flujo de
 * `MustVerifyEmail` se encarga del resto.
 *
 * Sin `auth()` / `request()` / `session()`: el adaptador de Fortify valida el
 * input y esta Action ejecuta el caso de uso, así que sirve igual desde un
 * comando artisan o un job de importación.
 *
 * **Este es el camino con `AUTH_INVITATIONS` apagado.** La cuenta nace activa
 * —el default de la columna `account_status`— y sin rol: el registro abierto no
 * sabe qué rol darle a nadie. Con el toggle encendido el adaptador manda a
 * `InvitationRedeemAction`, que sí conoce las dos cosas porque las trae escritas
 * el código.
 */
final class AuthUserRegisterAction extends Action
{
    public function handle(RegisterData $data): User
    {
        return User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => Hash::make($data->password),
        ]);
    }
}
