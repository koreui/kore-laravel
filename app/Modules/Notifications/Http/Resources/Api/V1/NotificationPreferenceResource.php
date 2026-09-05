<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Support\NotificationCategories;
use Illuminate\Http\Request;

/**
 * La preferencia **efectiva** de una categoría: la fila guardada o el default,
 * ya resueltos.
 *
 * El cliente no tiene que saber que una fila ausente significa «nunca lo
 * configuró»: pide sus preferencias y recibe el catálogo completo con la
 * etiqueta traducida, listo para pintar interruptores.
 *
 * @mixin NotificationPreferenceData
 */
final class NotificationPreferenceResource extends BaseApiResource
{
    /**
     * @return array{category: string, label: string, in_app: bool, mail: bool, push: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'category' => $this->resource->category,
            'label' => resolve(NotificationCategories::class)->label($this->resource->category),
            'in_app' => $this->resource->inApp,
            'mail' => $this->resource->mail,
            'push' => $this->resource->push,
        ];
    }
}
