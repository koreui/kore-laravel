<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Auth\Support\DemoAccounts;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Entrar como otro usuario para probar a mano lo que ve cada rol.
 *
 * **No es impersonation de verdad**: no se guarda la identidad original ni hay
 * «volver a ser admin». La sesión se cambia por la del objetivo y punto; para
 * volver se elige otra cuenta en el mismo switcher. Si algún día hace falta la
 * de verdad —con rastro en el log de actividad y botón de vuelta—, será otra
 * Action.
 *
 * Doble candado, y los dos lanzan en vez de devolver `false`:
 *
 *   1. **Sólo en `local`.** Fuera de ahí la ruta ni siquiera se registra, pero
 *      esta comprobación es la última línea: quien llegue por otro camino —una
 *      llamada directa, un test mal escrito, un `tinker` contra el servidor
 *      equivocado— se encuentra la puerta cerrada igual.
 *   2. **Sólo cuentas de un dominio reservado** ({@see DemoAccounts}). Son las
 *      que siembran `DatabaseSeeder` y `E2eSeeder`, y son las únicas que no
 *      pueden ser de una persona real. Sin este candado, el atajo entraría en
 *      la cuenta de cualquiera que hubiese acabado en una base de desarrollo:
 *      un volcado de producción anonimizado a medias, por ejemplo.
 *
 * Sin `session()` ni `auth()` (R19): la Action abre la sesión con el guard y
 * quien la llama —el componente Livewire, que sí vive en la capa Http— se
 * ocupa de regenerar el identificador de sesión.
 */
final class AuthDevImpersonateUserAction extends Action
{
    public function handle(User $target): void
    {
        if (! App::isLocal()) {
            throw new RuntimeException('El switcher de cuentas sólo existe en el entorno local.');
        }

        if (! DemoAccounts::includes($target->email)) {
            throw new RuntimeException(sprintf(
                '«%s» no es una cuenta de demostración: el switcher sólo entra en cuentas de un dominio reservado (%s).',
                $target->email,
                DemoAccounts::description(),
            ));
        }

        Auth::login($target);
    }
}
