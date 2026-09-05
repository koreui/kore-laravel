<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationPreferenceUpdateAction;
use App\Modules\Notifications\Http\Requests\Api\V1\NotificationPreferenceUpdateRequest;
use App\Modules\Notifications\Http\Resources\Api\V1\NotificationPreferenceResource;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationPreferences;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las preferencias de notificación del usuario del token
 * (`api/v1/me/notification-preferences`).
 *
 * Devuelve siempre el **catálogo completo con los defaults ya aplicados**: la
 * app no tiene que saber que una fila ausente significa «nunca lo configuró», y
 * una categoría nueva aparece sola en su pantalla de ajustes sin publicar
 * versión.
 *
 * `meta.push_available` dice si tiene sentido enseñar el interruptor de push:
 * sin el módulo Devices no hay dónde guardar un token, y ofrecer un interruptor
 * que no hace nada es prometer algo que no ocurre.
 */
#[Group('Notifications')]
final class NotificationPreferenceController extends ApiController
{
    /**
     * Mis preferencias de notificación.
     */
    #[ApiResponse(200, type: NotificationPreferenceResource::class)]
    public function index(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return $this->respondWithPreferences($this->user($request), $preferences);
    }

    /**
     * Guarda la preferencia de una categoría.
     *
     * `PUT` con el estado completo de esa categoría —los tres canales—; ver
     * `NotificationPreferenceUpdateRequest`. Responde con el catálogo entero
     * ya recalculado, que es lo que la pantalla necesita repintar.
     */
    #[ApiResponse(200, type: NotificationPreferenceResource::class)]
    public function update(
        NotificationPreferenceUpdateRequest $request,
        NotificationPreferences $preferences,
        NotificationPreferenceUpdateAction $action,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->toData();

        $this->authorize('update', new NotificationPreference([
            'user_id' => $user->getKey(),
            'category' => $data->category,
        ]));

        $action->handle($user, $data);

        return $this->respondWithPreferences($user, $preferences);
    }

    private function respondWithPreferences(User $user, NotificationPreferences $preferences): JsonResponse
    {
        return $this->respond(
            NotificationPreferenceResource::collection(
                array_values($preferences->all((int) $user->getKey())),
            ),
            meta: ['push_available' => (bool) config('kore-app.devices.enabled', false)],
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
