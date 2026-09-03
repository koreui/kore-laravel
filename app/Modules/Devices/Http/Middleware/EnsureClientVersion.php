<?php

declare(strict_types=1);

namespace App\Modules\Devices\Http\Middleware;

use App\Exceptions\UpgradeRequiredException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el paso a los clientes por debajo de `devices.min_app_version`.
 *
 * Alias `devices.version`, registrado por `DevicesModuleServiceProvider` cuando
 * el toggle está encendido. Es **opt-in por ruta** y no va en el grupo `api` a
 * propósito: el día que se sube el mínimo, un endpoint de login o de
 * diagnóstico tiene que seguir respondiendo para que la app pueda decirle al
 * usuario qué hacer. Se aplica así:
 *
 * ```php
 * Route::get('/expedientes', [...])->middleware('devices.version');
 * ```
 *
 * **Sin cabecera se pasa.** Los clientes web no mandan `X-App-Version` y no se
 * actualizan por una tienda: no hay nada que exigirles. La cabecera es una
 * declaración voluntaria del cliente que sí tiene ciclo de publicación.
 *
 * **El 426 pasa por `ApiExceptionRenderer` como cualquier otro error** (R54):
 * este middleware lanza `UpgradeRequiredException` con el mensaje ya formado
 * (versión del cliente y mínima admitida) y el renderer lo convierte en
 * `{ error: { code: 'upgrade_required', message } }`, sin `details`, que en
 * este contrato es exclusiva del 422. «Actualiza la app» y «algo salió mal»
 * piden pantallas distintas, y por eso el código tiene caso propio en
 * `ApiErrorCode` en vez de caer en `http_error`.
 */
final class EnsureClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->header('X-App-Version');

        if (! is_string($client) || trim($client) === '') {
            return $next($request);
        }

        $minimum = (string) config('devices.min_app_version', '0.0.0');

        if ($minimum === '' || version_compare($client, $minimum, '>=')) {
            return $next($request);
        }

        // R34: los datos van por placeholder, nunca interpolados dentro del
        // `__()`, para que la traducción sea una frase entera.
        throw new UpgradeRequiredException(__(
            'Tu versión :version ya no está soportada. Actualiza la aplicación a la :minimum o superior para continuar.',
            ['version' => $client, 'minimum' => $minimum],
        ));
    }
}
