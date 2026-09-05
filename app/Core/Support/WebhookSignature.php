<?php

declare(strict_types=1);

namespace App\Core\Support;

use Carbon\CarbonImmutable;

/**
 * La firma HMAC-SHA256 de un webhook: cómo se calcula y cómo se comprueba.
 *
 * Vive en `Core` y no en el módulo a propósito, porque hacen falta **los dos
 * lados**: el que manda (`App\Modules\Webhooks`) y el que recibe
 * (`VerifyWebhookSignature`, y el derivado que exponga un endpoint para recibir
 * webhooks de otro kore). Es una clase pura —sin contenedor, sin base, sin
 * `request()`— para que se pueda probar con tres cadenas y un entero.
 *
 * ## Qué se firma
 *
 * `"{timestamp}.{body}"`, donde `body` es el JSON **tal cual viaja**, byte a
 * byte. No se firma un array ni un JSON reserializado: dos serializaciones del
 * mismo array pueden diferir en el orden de las claves o en el escape de una
 * barra, y entonces la firma que calcula el receptor no es la que calculó el
 * emisor. Por eso quien verifica usa `$request->getContent()` y nunca
 * `$request->all()`.
 *
 * El timestamp entra en la carga —y no sólo en una cabecera— porque si no,
 * cambiarlo no invalidaría la firma y la ventana temporal no serviría de nada:
 * cualquiera podría reenviar la petición capturada con una hora nueva.
 *
 * ## La cabecera
 *
 * ```
 * X-Kore-Signature: t=1772668800,v1=6f1c…
 * ```
 *
 * El `v1=` es el número de esquema, y existe para poder cambiar el algoritmo
 * sin romper a los receptores: el día que haya un `v2`, la cabecera puede
 * llevar los dos y cada quien verifica el que entienda.
 *
 * ## La ventana
 *
 * `verify()` rechaza una firma cuyo timestamp esté a más de `$toleranceSeconds`
 * del momento actual, en cualquiera de los dos sentidos. Hacia atrás porque una
 * petición capturada no puede reenviarse mañana; hacia delante porque un reloj
 * adelantado en el emisor no puede comprar tiempo indefinido.
 *
 * La comparación final es `hash_equals()`, que tarda lo mismo acierte o falle:
 * un `===` sobre cadenas corta en el primer byte distinto, y ese tiempo es
 * medible desde fuera.
 */
final class WebhookSignature
{
    /** Esquema de firma que va en la cabecera. */
    public const string SCHEME = 'v1';

    /**
     * La firma de un cuerpo para un momento dado.
     *
     * @param string $secret el secreto compartido con el endpoint
     * @param string $timestamp segundos epoch, como cadena: es lo que viaja en
     *                          la cabecera y lo que entra en la carga firmada
     * @param string $body el cuerpo exacto que se va a mandar
     */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * La cabecera `X-Kore-Signature` completa: `t=<ts>,v1=<hex>`.
     */
    public static function header(string $timestamp, string $signature): string
    {
        return sprintf('t=%s,%s=%s', $timestamp, self::SCHEME, $signature);
    }

    /**
     * ¿La firma cuadra y está dentro de la ventana?
     *
     * `$now` es opcional para que un test pueda situarse en el tiempo sin tocar
     * el reloj del proceso; en producción no lo pasa nadie.
     */
    public static function verify(
        string $secret,
        string $timestamp,
        string $body,
        string $signature,
        int $toleranceSeconds = 300,
        ?int $now = null,
    ): bool {
        if ($signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $reference = $now ?? CarbonImmutable::now()->getTimestamp();

        if (abs($reference - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }

    /**
     * Desarma una cabecera `t=…,v1=…`.
     *
     * Devuelve `null` si falta cualquiera de las dos partes, que es lo que hace
     * el receptor cuando la cabecera no viene o viene a medias. Se acepta
     * cualquier orden y se ignora lo que no se entienda, para que añadir un
     * `v2=` mañana no rompa a quien sólo lee `v1`.
     *
     * @return array{timestamp: string, signature: string}|null
     */
    public static function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            }

            if ($key === self::SCHEME) {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null || $timestamp === '' || $signature === '') {
            return null;
        }

        return ['timestamp' => $timestamp, 'signature' => $signature];
    }
}
