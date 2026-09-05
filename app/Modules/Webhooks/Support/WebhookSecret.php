<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Support;

use Illuminate\Support\Str;

/**
 * De dónde sale el secreto compartido con un endpoint.
 *
 * Vive aquí y no dentro de una Action porque lo usan dos: el alta
 * (`WebhookEndpointCreateAction`) y la rotación
 * (`WebhookEndpointRotateSecretAction`). Que la longitud y el generador sean
 * los mismos en los dos caminos tiene que estar escrito en un solo sitio; y una
 * Action expone un único `handle()` público (R1), así que no puede prestárselo
 * a la otra.
 *
 * 48 caracteres de `Str::random()`, que por debajo es `random_bytes()`: unos
 * 285 bits de entropía. No se deja elegir al suscriptor —esto no es una
 * contraseña que nadie tenga que recordar, se copia una vez y se pega en el
 * otro lado—, así que no hay razón para aceptar menos.
 */
final class WebhookSecret
{
    /** Caracteres del secreto. */
    public const int LENGTH = 48;

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }
}
