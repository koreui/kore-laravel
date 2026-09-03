<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `api.json`. Fuerza `Accept: application/json` en la petición.
 *
 * Va en el grupo `api` porque los clientes reales no siempre mandan el header:
 * al subir un `FormData` desde React Native se omiten las cabeceras a propósito
 * para que el runtime ponga el `boundary`, y `curl` no manda ninguna. Sin
 * `Accept`, Laravel decide que el cliente quiere HTML y un error de validación
 * llega como un 302 hacia una pantalla de login que no existe.
 *
 * Es la primera mitad del cinturón; la otra es el
 * `$exceptions->shouldRenderJsonWhen(...)` de `bootstrap/app.php`, que mira la
 * URL y no la cabecera, y por eso cubre también lo que revienta antes de que
 * este middleware haya corrido.
 */
final class ForceJsonResponse
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
