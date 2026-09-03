<?php

declare(strict_types=1);

use App\Core\Http\Api\Exceptions\ApiExceptionRenderer;
use App\Core\Http\Api\Middleware\ApiAuditLogger;
use App\Core\Http\Api\Middleware\ApiCacheableResponse;
use App\Core\Http\Api\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Contrato de la API (App\Core\Http\Api). Ver docs/guides/api.md.
            'api.json' => ForceJsonResponse::class,
            'api.cache' => ApiCacheableResponse::class,
            'api.audit' => ApiAuditLogger::class,
        ]);

        // Las cabeceras de seguridad las emite la aplicación (config/security.php),
        // no sólo el Nginx del contenedor: así viajan con el código y funcionan
        // en cualquier hosting. Van en el grupo `web` porque protegen lo que un
        // navegador interpreta —HTML, CSS, JS, iframes—; `/health/json` y las
        // rutas de API devuelven JSON a un cliente que no tiene CSP ni frames.
        $middleware->web(append: [SecurityHeaders::class]);

        /*
         * Dos de los tres middleware de la API van en el grupo, y van
         * PREPEND —por delante del throttle—, no append:
         *
         *   - `api.json` fuerza el `Accept` antes de que nada pueda fallar, así
         *     que también el error que se rinde dentro del propio throttle sale
         *     en JSON.
         *   - `api.audit` es el más externo de los dos por la misma razón al
         *     revés: detrás del throttle, el 429 —la petición que más interesa
         *     auditar— se rendiría sin dejar línea de log.
         *
         * `api.cache` no está aquí: se pone endpoint a endpoint
         * (`->middleware('api.cache:3600')`), porque cachear lo que cambia en
         * cada petición es pagar un sha1() para nada.
         *
         * El grupo `api` del esqueleto de Laravel no trae throttle. Los tres
         * limiters (`api`, `api-auth`, `api-uploads`) se definen en
         * AppServiceProvider a partir de config/kore-api.php.
         */
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            ApiAuditLogger::class,
        ]);

        $middleware->throttleApi();

        // La app corre detrás del Nginx del contenedor (docker/nginx/nginx.conf),
        // que reenvía la petición por X-Forwarded-*. Sin confiar en ese proxy,
        // $request->ip() sería siempre la IP interna del contenedor y romperían
        // el rate limiting por IP, los logs y el contexto de Sentry.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sin esto el SDK de Sentry NO recibe las excepciones no capturadas.
        // Es no-op cuando SENTRY_LARAVEL_DSN está vacío.
        Integration::handles($exceptions);

        /*
         * La API responde JSON aunque el cliente no mande `Accept:
         * application/json`. Mira la URL y no la cabecera a propósito: es lo
         * único que sigue siendo cierto cuando la petición revienta antes de
         * llegar al middleware `api.json` (una ruta inexistente, un throttle,
         * un JSON mal formado). Sin esto, un error de validación llega al
         * cliente móvil como un 302 opaco hacia una pantalla de login.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Todo `Throwable` de una petición `api/*` sale con el envelope de
         * error del contrato: `{ error: { code, message, details? } }`. Fuera de
         * `api/*` el renderer devuelve null y Laravel sigue como siempre (el
         * callback del 419 de abajo, y su render por defecto).
         *
         * Ver App\Core\Http\Api\Exceptions\ApiExceptionRenderer, R54 y
         * docs/guides/api.md.
         */
        $exceptions->render(new ApiExceptionRenderer);

        /*
         * Un 419 en el escritorio casi nunca es un ataque: es la sesión que
         * caducó con la pestaña abierta, o el mismo formulario enviado dos
         * veces —el primer POST migró la sesión y el segundo llega sin token
         * válido—. La pantalla «Page Expired» de Laravel deja al usuario en un
         * callejón sin salida y sin decirle qué hacer, así que se le devuelve a
         * un sitio conocido con un aviso.
         *
         * Se tipa sobre `HttpException` y se filtra por el status a propósito:
         * el handler convierte `TokenMismatchException` en `HttpException(419)`
         * dentro de `prepareException()`, **antes** de evaluar los callbacks de
         * render, así que un callback tipado sobre la excepción original nunca
         * se dispararía.
         *
         * Quedan fuera tres clientes que ya saben qué hacer con un 419 y para
         * los que una redirección sería peor: los que esperan JSON, Livewire
         * (recarga la página por su cuenta) y la API, que responde con su
         * propio contrato.
         */
        $exceptions->render(function (HttpException $e, Request $request): ?RedirectResponse {
            if ($e->getStatusCode() !== 419 || $request->expectsJson() || $request->hasHeader('X-Livewire') || $request->is('api/*')) {
                return null;
            }

            return auth()->check()
                ? redirect()->intended(route('dashboard'))
                : to_route('login')->withErrors([
                    'email' => __('Tu sesión expiró. Vuelve a intentarlo.'),
                ]);
        });
    })->create();
