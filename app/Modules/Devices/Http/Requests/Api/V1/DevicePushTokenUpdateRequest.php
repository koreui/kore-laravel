<?php

declare(strict_types=1);

namespace App\Modules\Devices\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;
use App\Modules\Devices\Data\DevicePushTokenData;

/**
 * `PUT /api/v1/devices/current/push-token`.
 *
 * Extiende `BaseApiRequest` y no `FormRequest` (R54): es lo que convierte un
 * fallo de validación en el 422 con `details` en vez de un redirect 302.
 * `authorize()` no se toca — la autorización es del controller, contra la
 * Policy (R25).
 *
 * `max:1024` no es un número redondo cualquiera: los tokens de FCM rondan los
 * 160 caracteres y los de Web Push llegan a varios cientos. El tope está para
 * que nadie use esta columna como almacenamiento libre, no para acotar el
 * formato de un proveedor concreto.
 */
final class DevicePushTokenUpdateRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'push_token' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * Lo validado, ya como dato (R8): el controller no vuelve a tocar el array.
     */
    public function toData(): DevicePushTokenData
    {
        return new DevicePushTokenData(
            pushToken: (string) $this->string('push_token'),
        );
    }
}
