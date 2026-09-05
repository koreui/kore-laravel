<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;
use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Support\NotificationCategories;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/me/notification-preferences`.
 *
 * La categoría se valida contra el catálogo **del config** y no contra
 * `App\Core\Enums\NotificationCategory`: si validara contra el enum, un
 * derivado con categorías propias recibiría un 422 al guardar las suyas.
 *
 * Los tres canales son obligatorios a propósito. Un `PUT` que aceptara campos
 * sueltos sería un `PATCH` disfrazado, y con tres booleanos la ambigüedad no
 * compensa: el cliente manda el estado completo de esa categoría y no hay dudas
 * sobre qué pasa con lo que no mandó.
 */
final class NotificationPreferenceUpdateRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(resolve(NotificationCategories::class)->keys())],
            'in_app' => ['required', 'boolean'],
            'mail' => ['required', 'boolean'],
            'push' => ['required', 'boolean'],
        ];
    }

    public function toData(): NotificationPreferenceData
    {
        return new NotificationPreferenceData(
            category: $this->string('category')->value(),
            inApp: $this->boolean('in_app'),
            mail: $this->boolean('mail'),
            push: $this->boolean('push'),
        );
    }
}
