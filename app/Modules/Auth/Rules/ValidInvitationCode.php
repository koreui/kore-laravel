<?php

declare(strict_types=1);

namespace App\Modules\Auth\Rules;

use App\Modules\Auth\Models\InvitationCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El código de invitación existe y todavía acepta un registro.
 *
 * Dice **por qué** no sirve —caducado, agotado o inexistente— en vez de un
 * «código inválido» para todo. La distinción no filtra nada útil a quien
 * prueba códigos al azar (para un código que no existe el mensaje es genérico)
 * y le ahorra un ticket de soporte a quien tiene uno de verdad que ya venció.
 *
 * Vive en `Rules/` y no dentro del adaptador de Fortify porque también la usa
 * cualquier otra puerta que quiera pedir código: un endpoint de registro por
 * API, un comando de alta masiva. Sin `auth()` ni `request()` (R19): lo único
 * que necesita es la cadena que le pasan.
 */
final class ValidInvitationCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail(__('Necesitas un código de invitación para crear una cuenta.'));

            return;
        }

        $invitation = InvitationCode::findByCode($value);

        if (! $invitation instanceof InvitationCode) {
            $fail(__('El código de invitación no es válido.'));

            return;
        }

        $reason = $invitation->unavailableReason();

        if ($reason !== null) {
            $fail($reason);
        }
    }
}
