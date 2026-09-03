<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cabeceras fijas
    |--------------------------------------------------------------------------
    |
    | Se añaden a toda respuesta del grupo `web` desde
    | `App\Http\Middleware\SecurityHeaders`, que NO pisa una cabecera que la
    | respuesta ya traiga: un controller que necesite otra cosa (una página
    | embebible, por ejemplo) la fija él y el middleware la respeta.
    |
    | `X-XSS-Protection` no está aquí a propósito: el filtro XSS de los
    | navegadores está retirado desde Chrome 78 y llegó a introducir sus propias
    | vulnerabilidades. `docker/nginx/nginx.conf` la sigue enviando por defensa
    | en profundidad para clientes antiguos; la aplicación no la emite.
    |
    */

    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],

    /*
    |--------------------------------------------------------------------------
    | HSTS
    |--------------------------------------------------------------------------
    |
    | Sólo se emite sobre HTTPS: en HTTP el navegador la ignora por spec y en
    | local sólo sirve para dejar el dominio clavado en https durante un año.
    |
    | `preload` en false a propósito: entrar en la lista de precarga de los
    | navegadores es una decisión con marcha atrás lenta (semanas), y exige que
    | TODOS los subdominios sirvan HTTPS. Se activa a mano cuando eso es cierto.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', true),
        'max_age' => 31536000,
        'include_subdomains' => true,
        'preload' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | La política se construye a partir de `directives` como
    | `nombre valor valor; nombre valor`. Con `report_only` en true se emite como
    | `Content-Security-Policy-Report-Only`: el navegador informa pero no
    | bloquea, que es la única forma sensata de estrenar una CSP sin romper la
    | aplicación. Nunca se emiten las dos cabeceras a la vez.
    |
    | Para permitir un origen nuevo (un CDN, un iframe de pagos, un script de
    | analítica) se edita ESTE archivo, no el Nginx: así la política viaja con el
    | código, se prueba en `tests/Feature/SecurityHeadersTest.php` y funciona
    | igual en cualquier hosting.
    |
    */

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),

        // Report-only por defecto: se observa antes de bloquear. Producción pasa
        // a false cuando el informe esté limpio.
        'report_only' => (bool) env('CSP_REPORT_ONLY', true),

        // Endpoint que recibe los informes (Sentry lo ofrece). Vacío = sin report-uri.
        'report_uri' => env('CSP_REPORT_URI'),

        'directives' => [
            'default-src' => ["'self'"],

            // Livewire 4 y Alpine necesitan inline + eval. Cuando exista build
            // CSP de Alpine, se quita.
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],

            // fonts.bunny.net: los dos layouts (`components/layouts/app` y
            // `components/layouts/public`) cargan de ahí Inter y JetBrains Mono
            // con un <link rel="stylesheet">, y la hoja referencia los .woff2
            // del mismo origen. Es el único origen externo del boilerplate.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],

            // wss: para Reverb, que es opcional pero se instala sin tocar la CSP.
            'connect-src' => ["'self'", 'wss:'],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],
    ],

];
