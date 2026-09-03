<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Models\User;
use App\Modules\Auth\Data\ApiTokenData;
use Illuminate\Http\Request;

/**
 * La respuesta de `POST /api/v1/auth/login` y `/auth/refresh`.
 *
 * Trae el token **y** el usuario a propósito: sin el segundo, todo cliente
 * encadena un `GET /auth/me` inmediatamente después del login para saber a qué
 * pantalla mandar a quien acaba de entrar, y esa segunda petición está en el
 * camino crítico de cada arranque de la app.
 *
 * `expires_at` en ISO 8601 y no en segundos restantes: un `expires_in: 2592000`
 * obliga al cliente a saber cuándo empezó a contar, y el reloj que tiene a mano
 * es el suyo, no el del servidor.
 *
 * `token_type` es constante y sobra técnicamente —siempre es `Bearer`—, pero es
 * lo que un cliente OAuth-ish espera encontrar para componer la cabecera sin
 * hardcodearla.
 *
 * Toma dos argumentos porque el token y su dueño son dos cosas distintas: el
 * DTO no lleva el `User` dentro (R8) y el resource no lo va a buscar.
 * `ApiTokenResource::make($token, $user)` funciona igual que cualquier otro
 * `make()`: `JsonResource::make()` reenvía todo lo que recibe al constructor.
 *
 * Scramble deja un aviso `JR001` («cannot infer the resource model») sobre esta
 * clase y no hay forma de callarlo: envuelve un DTO y no un modelo Eloquent, que
 * es lo único que sabe buscar. El schema que publica es correcto —lo lee del
 * `toArray()`—, así que el aviso es ruido, no un fallo.
 *
 * @property-read ApiTokenData $resource
 */
final class ApiTokenResource extends BaseApiResource
{
    public function __construct(
        private readonly ApiTokenData $token,
        private readonly User $user,
    ) {
        parent::__construct($token);
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, user: array<string, mixed>}
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->token->expiresAt,
            'user' => UserMeResource::make($this->user)->toArray($request),
        ];
    }
}
