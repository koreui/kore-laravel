<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

/**
 * Revoca una passkey del usuario.
 *
 * Delega en la acción del paquete —que es quien borra la fila y despacha
 * `PasskeyDeleted`— para que el evento salga igual venga la petición de la
 * pantalla Livewire o del `DELETE /user/passkeys/{passkey}` de Fortify.
 *
 * R19 · el actor llega por parámetro, no de `auth()`: así se puede revocar una
 * credencial desde un comando de soporte o un job de baja de cuenta.
 *
 * Esta Action **no autoriza**: la decisión de «¿es suya?» vive en
 * `PasskeyPolicy` y la toma quien llama (R25).
 */
final class AuthPasskeyDeleteAction extends Action
{
    public function __construct(private readonly DeletePasskey $deletePasskey) {}

    public function handle(User $actor, Passkey $passkey): void
    {
        ($this->deletePasskey)($actor, $passkey);
    }
}
