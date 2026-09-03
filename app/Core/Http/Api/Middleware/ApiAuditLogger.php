<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `api.audit`. Una línea de log estructurada por petición de la API.
 *
 * Va en el grupo `api`, y **antes** del `throttle`: si se pusiera detrás, el
 * 429 —justo la petición que interesa auditar— se rendiría sin dejar rastro.
 *
 * Qué se registra: método, ruta, nombre de ruta, usuario, status, duración e
 * IP. Qué no: **el cuerpo**. Un log de peticiones que guarda el body acaba
 * guardando contraseñas, tokens y datos personales en un archivo que se rota a
 * otro disco y sobrevive a cualquier política de retención.
 *
 * El canal es `api` (`config/logging.php`), un stack que por defecto apunta al
 * mismo `single` que el resto. Un despliegue que quiera separarlo o mandarlo a
 * un agregador cambia `LOG_API_STACK` sin tocar código.
 */
final class ApiAuditLogger
{
    /**
     * Canal de `config/logging.php` al que van las líneas de auditoría.
     */
    public const string CHANNEL = 'api';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        $route = $request->route();

        Log::channel(self::CHANNEL)->info('api.request', [
            'method' => $request->getMethod(),
            'path' => '/'.$request->path(),
            'route' => $route instanceof Route ? $route->getName() : null,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
