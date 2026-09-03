<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Models\User;
use App\Modules\Auth\Http\Resources\Api\V1\UserMeResource;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El usuario autenticado.
 *
 * Primer endpoint del boilerplate que cumple el contrato de R54 y, por eso, el
 * ejemplo del que copiar: controller que extiende `ApiController`, respuesta
 * por `respond()`, representación en un `BaseApiResource`, ruta con nombre bajo
 * `api/v1` y errores por `ApiExceptionRenderer`.
 *
 * @see docs/guides/api.md
 */
#[Group('Auth')]
final class UserController extends ApiController
{
    /**
     * Perfil del usuario autenticado.
     *
     * Devuelve la identidad del portador del token junto con sus roles y
     * permisos, para que un cliente pueda pintar su menú sin una segunda
     * petición.
     */
    #[ApiResponse(200, type: UserMeResource::class)]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // La ruta va detrás de `auth:sanctum`, así que llegar aquí sin usuario
        // es un error de cableado, no un caso de uso. Se lanza en vez de
        // abortar: R20 deja `abort*()` para la capa Http de la aplicación, y el
        // renderer convierte esto en el 500 del contrato.
        if (! $user instanceof User) {
            throw new RuntimeException('GET /api/v1/user requiere el middleware auth:sanctum.');
        }

        return $this->respond(UserMeResource::make($user));
    }
}
