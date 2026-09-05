<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Core\Data\Data;

/**
 * Lo que hace falta para dar de alta o actualizar un endpoint.
 *
 * No lleva el secreto: lo genera la Action, nunca el formulario. Que un
 * suscriptor pudiera elegir su propia clave compartida sería aceptar la
 * entropía que a él le apeteciera, y un secreto de webhook no es una contraseña
 * que alguien tenga que recordar.
 */
final class WebhookEndpointData extends Data
{
    /**
     * @param array<int, string> $events nombres de evento suscritos; `['*']`
     *                                   son todos, presentes y futuros
     */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly array $events,
        public readonly bool $active = true,
    ) {}
}
