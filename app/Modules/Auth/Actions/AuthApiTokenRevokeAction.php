<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Auth\Events\ApiTokenRevoked;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retira tokens de API: uno concreto, o todos los del usuario (R1).
 *
 * Un solo caso de uso —«este token deja de valer»— con dos alcances, y no dos
 * Actions, porque la diferencia entre ambos es un `where` y el evento que sale
 * es el mismo: `ApiTokenRevoked` con `tokenId = null` significa «todos».
 *
 * `reason` es texto libre a propósito y viaja en el evento: `logout`,
 * `logout_all`, `refresh`, `permissions_changed`. Quien escuche —el registro de
 * dispositivos, una alerta de seguridad— necesita distinguir un cierre de
 * sesión voluntario de una revocación forzada, y esa distinción se pierde si
 * sólo se publica el hecho.
 *
 * Sin `auth()` (R19): el usuario llega por parámetro, así que el listener de
 * cambio de permisos la usa igual que el controller.
 */
final class AuthApiTokenRevokeAction extends Action
{
    /**
     * @param int|null $tokenId `null` revoca todos los tokens del usuario
     * @return int cuántas filas se borraron
     */
    public function handle(User $user, string $reason, ?int $tokenId = null): int
    {
        /** @var int $deleted */
        $deleted = $user->tokens()
            ->when($tokenId !== null, fn (Builder $query): Builder => $query->whereKey($tokenId))
            ->delete();

        // Revocar cero tokens no es un hecho que publicar: quien escucha
        // reaccionaría a un cierre de sesión que nunca ocurrió. El caso llega
        // solo (un logout-all de quien nunca pidió un token, un cambio de
        // permisos a un usuario que solo entra por el navegador).
        if ($deleted < 1) {
            return 0;
        }

        event(new ApiTokenRevoked(user: $user, tokenId: $tokenId, reason: $reason));

        return $deleted;
    }
}
