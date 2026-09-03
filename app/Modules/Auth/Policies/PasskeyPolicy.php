<?php

declare(strict_types=1);

namespace App\Modules\Auth\Policies;

use App\Models\User;
use Laravel\Passkeys\Passkey;

/**
 * Policy de las credenciales WebAuthn.
 *
 * Una passkey no es un recurso administrable: es una llave de la cuenta de una
 * persona. No hay permiso `passkeys.delete` ni rol que la alcance — la única
 * regla es la propiedad.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve
 * `true` para el superadmin, así que para ese rol esta policy **nunca se
 * evalúa**. Por eso el componente no busca la passkey por id suelto sino a
 * través de `$user->passkeys()`: la propiedad queda garantizada por la consulta
 * y no por el Gate. Esto es la policy como segunda barrera, no como única.
 */
final class PasskeyPolicy
{
    public function delete(User $user, Passkey $passkey): bool
    {
        return $passkey->user_id === $user->getKey();
    }
}
