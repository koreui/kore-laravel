<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Auth\Data\ApiDeviceData;
use App\Modules\Auth\Data\ApiTokenData;
use App\Modules\Auth\Events\ApiTokenIssued;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Emite un token de Sanctum para un usuario y un dispositivo (R1).
 *
 * La usan el login y el refresco, que es justo por lo que es una Action y no
 * dos métodos de un controller: la regla de qué abilities lleva un token y
 * cuánto vive se escribe una vez.
 *
 * **Las abilities son los permisos efectivos del usuario**
 * (`getAllPermissions()`: los que le dan sus roles más los directos), y si no
 * tiene ninguno el token sale con `[]`. Eso es lo contrario de lo que hace
 * Notarium, que cae a `['*']` cuando la lista está vacía y así le da el comodín
 * justo a quien no tiene nada — el fallback más peligroso posible escrito con
 * la mejor intención. Aquí un token sin abilities no abre ningún endpoint que
 * exija una, que es exactamente lo que significa «este usuario no puede nada».
 *
 * Sin `auth()` ni `request()` (R19): el actor y el dispositivo llegan por
 * parámetro, así que esto vale igual desde un comando artisan que emita un
 * token de servicio.
 */
final class AuthApiTokenIssueAction extends Action
{
    public function handle(User $user, ApiDeviceData $device): ApiTokenData
    {
        $abilities = array_values(array_map(
            strval(...),
            $user->getAllPermissions()->pluck('name')->all(),
        ));

        $expiresAt = $this->expiresAt();

        $newToken = $user->createToken($device->name, $abilities, $expiresAt);

        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;

        $tokenId = (int) $accessToken->getKey();

        event(new ApiTokenIssued(
            user: $user,
            tokenId: $tokenId,
            tokenName: $device->name,
            deviceId: $device->id,
            platform: $device->platform,
            appVersion: $device->appVersion,
        ));

        return new ApiTokenData(
            id: $tokenId,
            name: $device->name,
            plainTextToken: $newToken->plainTextToken,
            expiresAt: $expiresAt?->toIso8601String(),
            abilities: $abilities,
        );
    }

    /**
     * Caducidad de ESTE token, desde `kore-api.tokens.expires_minutes`.
     *
     * Va en la columna `expires_at` de la fila y no en `sanctum.expiration`
     * porque esa clave es global y **retroactiva**: al ponerla, todos los
     * tokens ya emitidos pasan a caducar contados desde su `created_at`, y una
     * integración que llevaba dos años funcionando se cae el día del deploy.
     * `null` deja el token sin caducidad, que sigue siendo una decisión
     * legítima cuando el ciclo de vida lo lleva la revocación.
     */
    private function expiresAt(): ?CarbonImmutable
    {
        $minutes = config('kore-api.tokens.expires_minutes');

        if ($minutes === null || $minutes === '') {
            return null;
        }

        return CarbonImmutable::now()->addMinutes((int) $minutes);
    }
}
