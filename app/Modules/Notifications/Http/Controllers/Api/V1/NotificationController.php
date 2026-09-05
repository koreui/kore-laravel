<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Models\User;
use App\Modules\Notifications\Actions\NotificationMarkAllReadAction;
use App\Modules\Notifications\Actions\NotificationMarkReadAction;
use App\Modules\Notifications\Http\Resources\Api\V1\NotificationResource;
use App\Modules\Notifications\Support\NotificationCategories;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * La bandeja del usuario del token (`api/v1/me/notifications`).
 *
 * **Todo cuelga de `me`**: una notificación es de quien la recibe y nadie
 * consulta la de otro, así que no hay `/notifications/{user}` que proteger. El
 * ámbito lo pone la relación (`$user->notifications()`), no un `where` que se
 * pueda olvidar — y encima de eso la Policy vuelve a comprobar la fila concreta
 * antes de marcarla (R25).
 *
 * Sin `abilities:` en las rutas, a diferencia de `api/v1/users`: las abilities
 * de un token son los permisos de su dueño, y aquí no hay permiso que pedir. Lo
 * que decide es de quién es la fila.
 *
 * Ver `docs/guides/api.md` y `docs/modules/notifications.md`.
 */
#[Group('Notifications')]
final class NotificationController extends ApiController
{
    /**
     * Mi bandeja.
     *
     * Paginación por cursor (`?per_page=`, `?cursor=`) con `meta.next_cursor`,
     * como el resto de listados de la API. Filtros opcionales: `unread=1` deja
     * sólo las no leídas y `category=` acota por área.
     *
     * `meta.unread_count` viaja en cada respuesta para que la app pinte el globo
     * sin una segunda llamada.
     */
    #[ApiResponse(200, type: NotificationResource::class)]
    public function index(Request $request, NotificationCategories $categories): JsonResponse
    {
        $user = $this->user($request);

        $query = $user->notifications()->getQuery();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $category = $request->string('category')->value();

        if ($category !== '' && $categories->has($category)) {
            $query->whereJsonContains('data->category', $category);
        }

        // Por `created_at` **y** por `id`: la paginación por cursor necesita un
        // orden total, y dos notificaciones del mismo `notifyMany` comparten la
        // marca de tiempo al segundo.
        $notifications = $this->paginateWithCursor(
            $query->latest()->orderByDesc('id'),
            $request,
        );

        return $this->respond(
            NotificationResource::collection($notifications),
            meta: [
                ...$this->cursorMeta($notifications),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        );
    }

    /**
     * Marca una notificación como leída.
     *
     * Responde con la notificación ya marcada y el contador actualizado, para
     * que la app no tenga que recargar la lista entera. Una notificación que no
     * es de quien pregunta no da 403 sino 404: decir «existe pero no es tuya»
     * confirmaría el uuid a quien lo estaba probando.
     */
    #[ApiResponse(200, type: NotificationResource::class)]
    public function markAsRead(Request $request, string $notification, NotificationMarkReadAction $action): JsonResponse
    {
        $user = $this->user($request);

        $found = $user->notifications()->whereKey($notification)->firstOrFail();

        $this->authorize('update', $found);

        $action->handle($user, $notification);

        return $this->respond(
            NotificationResource::make($found->refresh()),
            meta: ['unread_count' => $user->unreadNotifications()->count()],
        );
    }

    /**
     * Marca todas como leídas.
     *
     * Devuelve cuántas se marcaron y el contador a cero: es la respuesta que la
     * app necesita para apagar el globo sin volver a preguntar.
     */
    #[ApiResponse(200)]
    public function markAllAsRead(Request $request, NotificationMarkAllReadAction $action): JsonResponse
    {
        $user = $this->user($request);

        $this->authorize('viewAny', DatabaseNotification::class);

        return $this->respond([
            'marked' => $action->handle($user),
            'unread_count' => 0,
        ]);
    }

    /**
     * El usuario del token.
     *
     * Las rutas van detrás de `auth:sanctum`, así que llegar aquí sin usuario
     * no ocurre; el `abort` es la red que hace que Larastan —y quien lea— sepan
     * que a partir de esta línea hay un `User`.
     */
    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
