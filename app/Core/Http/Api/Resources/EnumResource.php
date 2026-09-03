<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Resources;

use BackedEnum;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Representación estándar de un enum en la API: `{ value, label }`.
 *
 * `value` es lo que el cliente manda de vuelta; `label` es lo que enseña al
 * usuario. Publicar sólo el `value` obliga a cada cliente a mantener su propia
 * tabla de traducciones, y publicar sólo el `label` hace imposible comparar.
 *
 * El label sale del método `label()` del enum si lo tiene —la convención del
 * boilerplate, ver `App\Core\Enums\SystemRole`— y del nombre del case si no.
 *
 * ```php
 * 'role' => EnumResource::make($user->systemRole()),
 * 'roles' => EnumResource::collection(SystemRole::assignable()),
 * ```
 */
final class EnumResource extends BaseApiResource
{
    /**
     * @return array{value: string|int, label: string}
     */
    public function toArray(Request $request): array
    {
        $enum = $this->resource;

        if (! $enum instanceof BackedEnum) {
            throw new InvalidArgumentException(sprintf(
                'EnumResource espera un BackedEnum, recibió %s.',
                get_debug_type($enum),
            ));
        }

        return [
            'value' => $enum->value,
            'label' => method_exists($enum, 'label') ? (string) $enum->label() : $enum->name,
        ];
    }
}
