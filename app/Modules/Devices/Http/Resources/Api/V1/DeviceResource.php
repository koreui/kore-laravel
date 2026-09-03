<?php

declare(strict_types=1);

namespace App\Modules\Devices\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Core\Http\Api\Resources\EnumResource;
use App\Models\User;
use App\Modules\Devices\Enums\Platform;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Support\CurrentApiToken;
use Illuminate\Http\Request;

/**
 * Un dispositivo tal y como lo ve su dueño.
 *
 * Lista blanca (R54): lo que no esté escrito aquí no sale. Y lo que
 * deliberadamente **no** está es tan importante como lo que está:
 *
 * - **`push_token`** — es una credencial de envío. Quien la tiene puede mandar
 *   notificaciones a ese teléfono. El modelo la marca `#[Hidden]` y este
 *   resource no la nombra: dos barreras para el mismo dato.
 * - **`id`** y **`access_token_id`** — el uuid es la identidad pública
 *   (`HasPublicUuid`); publicar el entero diría cuántos dispositivos y cuántos
 *   tokens hay en la instalación.
 * - **`device_id`** — lo elige el cliente y suele ser el identificador del
 *   aparato. No aporta nada al usuario y sí a quien quiera correlacionar
 *   cuentas.
 *
 * `current` es lo que permite a la app pintar «este dispositivo» y no ofrecer
 * cerrar la sesión en la que estás sin avisar. Se calcula contra el token de la
 * petición, así que el mismo dispositivo es `current` sólo cuando es él quien
 * pregunta.
 *
 * @mixin Device
 */
final class DeviceResource extends BaseApiResource
{
    /**
     * @return array{uuid: string, name: string|null, platform: EnumResource|null, app_version: string|null, last_seen_at: string|null, revoked_at: string|null, current: bool}
     */
    public function toArray(Request $request): array
    {
        $currentTokenId = CurrentApiToken::idFor($this->userOf($request));
        $platform = $this->platform;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'platform' => $platform instanceof Platform ? EnumResource::make($platform) : null,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'current' => $currentTokenId !== null && $this->access_token_id === $currentTokenId,
        ];
    }

    /**
     * El usuario de la petición, o `null` si el que llega no es uno nuestro.
     *
     * `$request->user()` está tipado como `Authenticatable`, que no sabe nada de
     * tokens de Sanctum; el `instanceof` es lo que le da un tipo con el que se
     * puede trabajar sin suponer.
     */
    private function userOf(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
