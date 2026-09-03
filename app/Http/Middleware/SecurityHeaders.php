<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad emitidas por la aplicación.
 *
 * Hasta la v1.2.0 las ponía sólo `docker/nginx/nginx.conf`, así que cualquier
 * despliegue fuera de ese Docker —Laravel Cloud, Forge, un `artisan serve`, un
 * hosting compartido— salía sin ninguna. Emitirlas aquí las hace parte del
 * artefacto: viajan con el código, se prueban con Pest y funcionan en cualquier
 * hosting. El Nginx del contenedor las mantiene como defensa en profundidad
 * para los estáticos que sirve él directamente, sin pasar por PHP.
 *
 * Toda la política vive en `config/security.php`.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $this->applyStaticHeaders($response);
        $this->applyStrictTransportSecurity($request, $response);
        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    /**
     * Las cabeceras fijas de `security.headers`.
     *
     * No se pisa una cabecera que la respuesta ya traiga: así un controller
     * puede anular una puntualmente (una página pensada para ir dentro de un
     * iframe ajeno fija su propio `X-Frame-Options` y el middleware la respeta).
     */
    private function applyStaticHeaders(Response $response): void
    {
        /** @var array<string, string> $headers */
        $headers = config('security.headers', []);

        foreach ($headers as $name => $value) {
            if ($response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }
    }

    /**
     * HSTS sólo sobre HTTPS.
     *
     * Sobre HTTP el navegador la ignora por spec, y en local sólo serviría para
     * dejar el dominio de desarrollo clavado en https durante un año.
     */
    private function applyStrictTransportSecurity(Request $request, Response $response): void
    {
        if (! (bool) config('security.hsts.enabled')) {
            return;
        }

        if (! $request->isSecure()) {
            return;
        }

        if ($response->headers->has('Strict-Transport-Security')) {
            return;
        }

        $value = 'max-age='.(int) config('security.hsts.max_age');

        if ((bool) config('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }

        if ((bool) config('security.hsts.preload')) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    /**
     * La CSP, en modo informe o en modo bloqueo, nunca las dos.
     *
     * Si la respuesta ya trae cualquiera de las dos cabeceras se deja como está:
     * añadir la otra dejaría al navegador con dos políticas y la más
     * restrictiva ganando, que es justo el efecto que nadie espera.
     */
    private function applyContentSecurityPolicy(Response $response): void
    {
        if (! (bool) config('security.csp.enabled')) {
            return;
        }

        if ($response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return;
        }

        $policy = $this->buildPolicy();

        if ($policy === '') {
            return;
        }

        $header = (bool) config('security.csp.report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $policy);
    }

    /**
     * `nombre valor valor; nombre valor`, con `report-uri` al final si lo hay.
     */
    private function buildPolicy(): string
    {
        /** @var array<string, array<int, string>> $directives */
        $directives = config('security.csp.directives', []);

        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = trim($name.' '.implode(' ', $sources));
        }

        $reportUri = config('security.csp.report_uri');

        if (is_string($reportUri) && trim($reportUri) !== '') {
            $parts[] = 'report-uri '.trim($reportUri);
        }

        return implode('; ', $parts);
    }
}
