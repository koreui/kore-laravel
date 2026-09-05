<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La URL de un endpoint apunta a una red pública, no a la de dentro.
 *
 * ## Qué evita
 *
 * SSRF. Sin esto, cualquiera con `webhooks.manage` da de alta
 * `https://169.254.169.254/latest/meta-data/iam/security-credentials/` y el
 * servidor —que sí llega ahí— le entrega las credenciales del rol de la
 * instancia dentro del cuerpo de un intento fallido, o `https://127.0.0.1:9200/`
 * y le enseña el Elasticsearch que no está expuesto a Internet. El emisor de
 * webhooks es un cliente HTTP con la dirección elegida por el usuario, que es
 * la definición del problema; lo único que lo cierra es no dejar elegir una
 * dirección de dentro.
 *
 * ## Cómo lo comprueba
 *
 * Se resuelve el host y se miran **las direcciones**, no el nombre: bloquear
 * la cadena `localhost` no sirve de nada cuando `interno.ejemplo.com` es un
 * registro A que apunta a `10.0.0.5`. Una IP literal se usa tal cual; un
 * nombre pasa por `gethostbynamel()`, y si no resuelve se rechaza — un endpoint
 * al que hoy no se puede llegar no es una integración, es una entrega que va a
 * agotar sus seis intentos.
 *
 * El veredicto lo da `filter_var()` con `FILTER_FLAG_NO_PRIV_RANGE` y
 * `FILTER_FLAG_NO_RES_RANGE`, que entre las dos cubren exactamente lo que hay
 * que cerrar: loopback (`127.0.0.0/8`, `::1`), link-local (`169.254.0.0/16`,
 * `fe80::/10`), privadas (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`,
 * `fc00::/7`), `0.0.0.0/8`, `::` y las IPv4 mapeadas en IPv6
 * (`::ffff:0:0/96`, que si no dejarían pasar `::ffff:127.0.0.1`).
 *
 * **Hay una carrera y se asume**: entre esta resolución y la entrega, el DNS
 * puede cambiar de respuesta (*DNS rebinding*). Cerrarla del todo pide resolver
 * y conectar contra la IP ya validada, que es cosa del cliente HTTP y no de una
 * regla de validación. Esto sube el listón de «cualquiera con acceso a la
 * pantalla» a «alguien que controla un dominio y sabe lo que hace», que es
 * donde tiene que estar.
 *
 * ## La válvula
 *
 * `kore-webhooks.allow_private_networks` (`WEBHOOKS_ALLOW_PRIVATE_NETWORKS`)
 * la desactiva entera. Existe porque hay instalaciones donde el receptor está
 * legítimamente en la red interna —un despliegue en un clúster privado que se
 * manda webhooks entre servicios— y ahí la regla estorba en vez de proteger.
 * Va en `false` por defecto **también en `local` y en `testing`**: si se
 * apagara sola en desarrollo, el único sitio donde se probaría de verdad sería
 * producción. Los tests que necesiten una URL interna la encienden a mano.
 */
final class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (self::allowsPrivateNetworks()) {
            return;
        }

        $host = self::hostOf($value);

        if ($host === null) {
            $fail(__('La URL del endpoint no tiene un host válido.'));

            return;
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            $fail(__('El host «:host» no resuelve a ninguna dirección.', ['host' => $host]));

            return;
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                $fail(__('La URL del endpoint apunta a una dirección de red interna (:address), y desde ahí un webhook puede leer servicios que no están expuestos.', [
                    'address' => $address,
                ]));

                return;
            }
        }
    }

    /**
     * ¿Se permiten redes internas en esta instalación?
     */
    public static function allowsPrivateNetworks(): bool
    {
        return (bool) config('kore-webhooks.allow_private_networks', false);
    }

    /**
     * ¿La dirección está fuera de los rangos internos y reservados?
     */
    public static function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * El host de una URL, sin los corchetes de una IPv6 literal.
     */
    public static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return trim($host, '[]');
    }

    /**
     * Las direcciones de un host: la suya si ya es una IP, y si no, lo que diga
     * el DNS.
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolved = gethostbynamel($host);

        return $resolved === false ? [] : $resolved;
    }
}
