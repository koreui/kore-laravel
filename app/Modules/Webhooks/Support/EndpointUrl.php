<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Support;

use App\Modules\Webhooks\Rules\PublicHttpUrl;
use InvalidArgumentException;

/**
 * Qué se admite como URL de un endpoint, en un solo sitio.
 *
 * Hay **dos** puertas por las que entra una URL y las dos tienen que decir lo
 * mismo: el formulario (`WebhookEndpointForm`) y las Actions de alta y edición,
 * que sirven igual desde un comando o un seeder, donde no hay validador que
 * valga. Es el mismo reparto que `EditableSettings` en Platform: la derivación
 * vive una vez y las dos capas la consultan, porque dos copias de la misma
 * condición divergen en cuanto alguien afloja una.
 *
 * Dos condiciones, y ninguna es cosmética:
 *
 * 1. **`https`** salvo en `local` — la firma protege la integridad, no la
 *    confidencialidad, y por `http` el payload viaja legible.
 * 2. **Red pública** — ver {@see PublicHttpUrl}: una URL interna convierte el
 *    emisor de webhooks en un lector de servicios que no están expuestos.
 */
final class EndpointUrl
{
    /** Longitud máxima de la columna `url`. */
    public const int MAX_LENGTH = 2048;

    /**
     * Las reglas de validación de la URL, para un Form Object o un FormRequest.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            self::httpsRequired() ? 'url:https' : 'url:http,https',
            'max:'.self::MAX_LENGTH,
            new PublicHttpUrl,
        ];
    }

    /**
     * Las mismas condiciones, fuera de una petición.
     *
     * @throws InvalidArgumentException si la URL no es admisible
     */
    public static function guard(string $url): void
    {
        if (self::httpsRequired() && ! str_starts_with(mb_strtolower($url), 'https://')) {
            throw new InvalidArgumentException(
                "La URL [{$url}] no usa https, y la firma de un webhook protege la integridad del mensaje, no su confidencialidad."
            );
        }

        if (PublicHttpUrl::allowsPrivateNetworks()) {
            return;
        }

        $host = PublicHttpUrl::hostOf($url);

        if ($host === null) {
            throw new InvalidArgumentException("La URL [{$url}] no tiene un host válido.");
        }

        foreach (self::addressesOf($host) as $address) {
            if (! PublicHttpUrl::isPublic($address)) {
                throw new InvalidArgumentException(
                    "La URL [{$url}] apunta a la dirección interna [{$address}]: enciende WEBHOOKS_ALLOW_PRIVATE_NETWORKS si esta instalación manda webhooks dentro de su propia red."
                );
            }
        }
    }

    /**
     * `https` obligatorio salvo en `local`, donde el receptor de al lado suele
     * ser un `php artisan serve` sin certificado y exigir TLS para probar una
     * integración sólo consigue que se pruebe en producción.
     */
    public static function httpsRequired(): bool
    {
        return (bool) config('kore-webhooks.require_https', true)
            && ! app()->environment('local');
    }

    /**
     * @return array<int, string>
     *
     * @throws InvalidArgumentException si el host no resuelve
     */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolved = gethostbynamel($host);

        if ($resolved === false) {
            throw new InvalidArgumentException("El host [{$host}] no resuelve a ninguna dirección.");
        }

        return $resolved;
    }
}
