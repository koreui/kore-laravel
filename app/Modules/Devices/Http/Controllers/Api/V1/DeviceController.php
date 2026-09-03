<?php

declare(strict_types=1);

namespace App\Modules\Devices\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use App\Models\User;
use App\Modules\Devices\Actions\DevicePushTokenUpdateAction;
use App\Modules\Devices\Actions\DeviceRevokeAction;
use App\Modules\Devices\Http\Requests\Api\V1\DevicePushTokenUpdateRequest;
use App\Modules\Devices\Http\Resources\Api\V1\DeviceResource;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Support\CurrentApiToken;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Los dispositivos del usuario autenticado.
 *
 * Es la pantalla de «dónde tengo la sesión abierta» que toda app con login
 * acaba necesitando, y las tres operaciones son las tres que un usuario pide:
 * verlos, cerrar uno, y decirle al servidor a dónde mandar las notificaciones.
 *
 * **Ningún endpoint acepta un `user_id`.** El dueño sale siempre del token, y
 * la consulta se acota por él antes de buscar nada: pedir el uuid de otro es un
 * 404, no un 403. Un 403 confirmaría que ese uuid existe, que es media
 * enumeración regalada (mismo criterio que las passkeys).
 *
 * @see docs/modules/devices.md
 * @see docs/guides/api.md
 */
#[Group('Devices')]
final class DeviceController extends ApiController
{
    /**
     * Dispositivos del usuario.
     *
     * Devuelve todos los del usuario autenticado —también los revocados, que
     * llevan su `revoked_at`— con `current: true` en el que hace la petición.
     */
    #[ApiResponse(200, type: DeviceResource::class)]
    public function index(Request $request): JsonResponse
    {
        /*
         * Sin `authorize()`, y no por descuido: listar «los míos» no es una
         * decisión que tomar. La autorización es el `where('user_id', ...)` de
         * abajo, igual que el componente de passkeys lista `$user->passkeys()`
         * en vez de consultar una policy que siempre diría que sí. Donde sí hay
         * una decisión —borrar— está la Policy (R25).
         */
        $user = $this->currentUser($request);

        /*
         * Orden por id descendente y no por `last_seen_at`: la paginación por
         * cursor compara las columnas del `ORDER BY`, y `last_seen_at` es
         * nullable —un dispositivo que nunca ha vuelto rompería el cursor—.
         * El id es único, monótono y nunca nulo, así que además no necesita
         * columna de desempate.
         */
        $devices = $this->paginateWithCursor(
            Device::query()->where('user_id', $user->getKey())->orderByDesc('id'),
            $request,
        );

        return $this->respond(DeviceResource::collection($devices), meta: $this->cursorMeta($devices));
    }

    /**
     * Revoca un dispositivo.
     *
     * Marca la fila y borra su token de Sanctum, así que el dispositivo deja de
     * poder llamar a la API. Si es el que hace la petición, el token que se
     * borra es el de esta misma petición: el 204 se responde y la siguiente
     * llamada es un 401.
     */
    #[ApiResponse(204)]
    public function destroy(Request $request, string $device, DeviceRevokeAction $action): Response
    {
        $user = $this->currentUser($request);

        // La propiedad la garantiza la consulta, no el Gate: el `Gate::before`
        // del superadmin haría pasar la policy sin mirar el dueño.
        $model = Device::query()
            ->where('user_id', $user->getKey())
            ->where('uuid', $device)
            ->firstOrFail();

        // Segunda barrera, y el único sitio donde se escribe la regla (R25).
        $this->authorize('delete', $model);

        $action->handle($model);

        return $this->respondNoContent();
    }

    /**
     * Actualiza el token de notificaciones del dispositivo en uso.
     *
     * El dispositivo se identifica por el token de Sanctum de la petición, no
     * por un id que mande el cliente. El servidor **no** valida el token contra
     * el proveedor: lo guarda.
     *
     * Responde 404 cuando el token en uso no tiene dispositivo registrado —una
     * sesión web, o un login sin `device_id`—: es un cliente que se cree un
     * dispositivo y no lo es, y decírselo con un 204 sería mentirle.
     */
    #[ApiResponse(204)]
    public function updatePushToken(
        DevicePushTokenUpdateRequest $request,
        DevicePushTokenUpdateAction $action,
    ): Response {
        $user = $this->currentUser($request);

        $device = $action->handle($user, CurrentApiToken::idFor($user), $request->toData());

        if (! $device instanceof Device) {
            throw (new ModelNotFoundException)->setModel(Device::class);
        }

        return $this->respondNoContent();
    }

    /**
     * El usuario del token.
     *
     * Las rutas van detrás de `auth:sanctum`, así que llegar aquí sin usuario es
     * un error de cableado, no un caso de uso. Se lanza en vez de abortar: R20
     * deja `abort*()` para la capa Http y el renderer convierte esto en el 500
     * del contrato.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Las rutas de api/v1/devices requieren el middleware auth:sanctum.');
        }

        return $user;
    }
}
