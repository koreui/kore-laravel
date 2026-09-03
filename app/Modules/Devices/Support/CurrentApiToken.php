<?php

declare(strict_types=1);

namespace App\Modules\Devices\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * El id del token de Sanctum con el que llega la petición en curso.
 *
 * Es la pieza que responde «¿cuál de mis dispositivos soy yo?», y la necesitan
 * dos capas distintas: el controller —para saber qué fila actualizar en
 * `PUT /devices/current/push-token`— y el resource —para marcar `current` en el
 * listado—. Vive aquí, en `Support/`, en vez de duplicada en las dos: es una
 * sola pregunta con una sola respuesta correcta.
 *
 * Devuelve `null` en los dos casos en que no hay token de verdad: sesión de
 * navegador (Sanctum inyecta un `TransientToken`, que no tiene fila ni id) o
 * petición sin autenticar. Ninguno de los dos es un error aquí: significan «esta
 * petición no viene de un dispositivo registrado».
 */
final class CurrentApiToken
{
    public static function idFor(?User $user): ?int
    {
        if (! $user instanceof User) {
            return null;
        }

        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
