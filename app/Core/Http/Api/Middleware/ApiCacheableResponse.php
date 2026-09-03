<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `api.cache`. ETag + `304 Not Modified` + `Cache-Control` privado.
 *
 * **No** va en el grupo `api`: se pone endpoint a endpoint, porque sólo tiene
 * sentido donde la respuesta cambia poco (catálogos, perfiles, configuración) y
 * en el resto sería un `sha1()` de la respuesta pagado en cada petición para
 * nada.
 *
 * ```php
 * Route::get('/catalogs', [CatalogController::class, 'index'])
 *     ->middleware('api.cache:3600');
 * ```
 *
 * `Cache-Control` es **private**: la respuesta depende del token que la pidió,
 * así que un proxy compartido no puede guardarla. Quien cachea es el cliente.
 *
 * Sólo actúa sobre `GET` con `200` y cuerpo JSON: un `POST` no se cachea, y un
 * error tampoco —un 404 con ETag convierte el primer fallo en permanente.
 */
final class ApiCacheableResponse
{
    /**
     * @param Closure(Request): Response $next
     * @param int|string $maxAge segundos; llega como string
     *                           cuando viene del alias
     *                           (`api.cache:3600`)
     */
    public function handle(Request $request, Closure $next, int|string $maxAge = 3600): Response
    {
        $response = $next($request);

        if (! $this->isCacheable($request, $response)) {
            return $response;
        }

        // `xxh128` y no `sha1`/`md5`: un ETag es un identificador de contenido,
        // no una firma —nadie va a fiarse de él para nada—, así que lo que
        // importa es que sea rápido y no colisione por accidente. Los dos
        // clásicos, además, los prohíbe el preset `security` de Pest, y con
        // razón: cualquiera que los vea en un diff tiene que pararse a pensar
        // si esta vez sí era criptográfico.
        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->setCache([
            'private' => true,
            'max_age' => max(0, (int) $maxAge),
        ]);

        // Vacía el cuerpo y deja la respuesta en 304 si el `If-None-Match` del
        // cliente coincide con el ETag que acabamos de calcular.
        $response->isNotModified($request);

        return $response;
    }

    private function isCacheable(Request $request, Response $response): bool
    {
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== Response::HTTP_OK) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', ''), 'json');
    }
}
