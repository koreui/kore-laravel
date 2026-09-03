<?php

declare(strict_types=1);

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
        ]);

        // Las cabeceras de seguridad las emite la aplicación (config/security.php),
        // no sólo el Nginx del contenedor: así viajan con el código y funcionan
        // en cualquier hosting. Van en el grupo `web` porque protegen lo que un
        // navegador interpreta —HTML, CSS, JS, iframes—; `/health/json` y las
        // rutas de API devuelven JSON a un cliente que no tiene CSP ni frames.
        $middleware->web(append: [SecurityHeaders::class]);

        // El grupo `api` del esqueleto de Laravel no trae throttle. El
        // limiter `api` se define en AuthModuleServiceProvider.
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
