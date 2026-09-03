<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;
use App\Modules\Auth\Data\ApiLoginData;

/**
 * `POST /api/v1/auth/login`.
 *
 * Sólo valida la **forma** de lo que llega. Que la contraseña sea la buena no
 * se comprueba aquí: un `FormRequest` que valida credenciales devolvería el
 * error bajo `details`, campo a campo, y eso es exactamente lo que no queremos
 * —«el email no existe» dicho con otras palabras—. Ese paso lo da el controller
 * con un único mensaje genérico (R28).
 *
 * `device_name` es obligatorio y es el nombre del token; ver `ApiDeviceData`.
 * `platform` es una lista cerrada para que el cliente que escriba `Android` con
 * mayúscula se entere en el 422 y no seis meses después, mirando una tabla con
 * cuatro grafías del mismo sistema operativo.
 */
final class LoginRequest extends BaseApiRequest
{
    /**
     * Plataformas que un cliente puede declarar.
     */
    public const array PLATFORMS = ['ios', 'android', 'web', 'cli'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'platform' => ['nullable', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Estado validado como DTO, igual que el `toData()` de un Form Object de
     * Livewire (R8). Llamar siempre después de `validated()`.
     */
    public function toData(): ApiLoginData
    {
        return new ApiLoginData(
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            deviceName: (string) $this->validated('device_name'),
            deviceId: $this->nullableString('device_id'),
            platform: $this->nullableString('platform'),
            appVersion: $this->nullableString('app_version'),
        );
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
