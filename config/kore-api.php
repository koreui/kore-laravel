<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Contrato de la API
    |--------------------------------------------------------------------------
    |
    | Los parámetros del contrato que implementa `App\Core\Http\Api`: versión,
    | paginación, documentación y rate limiting. Ver `docs/guides/api.md`.
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende la API sigue siendo `API_ENABLED` (`kore-app.api.enabled`), que
    | es lo que hace que `AuthModuleServiceProvider` cargue las rutas. Aquí sólo
    | vive cómo se comporta la API cuando está encendida.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético). Por eso `config/scramble.php` no lee estas claves y es
    | `App\Providers\ApiDocsServiceProvider` quien se las pasa a Scramble desde
    | su `boot()`.
    |
    */

    /*
     * Versión del contrato. Es el segmento de las rutas (`api/v1/...`), el
     * `info.version` del documento OpenAPI y el prefijo de los namespaces
     * (`Http\Controllers\Api\V1`). Subirla es publicar una API nueva, no editar
     * la que hay: la anterior sigue respondiendo hasta que se retire.
     */
    'version' => 'v1',

    /*
     * Paginación por cursor (`App\Core\Http\Api\Concerns\HandlesCursorPagination`).
     *
     * `max` no es un consejo: es el tope que se aplica a `?per_page=`. Sin él,
     * `?per_page=100000` es una denegación de servicio de un solo carácter.
     */
    'pagination' => [
        'default' => 25,
        'max' => 100,
    ],

    /*
     * Documentación OpenAPI (dedoc/scramble) en `/api/docs` y `/api/docs.json`.
     *
     * Apagada por defecto: en producción una spec pública es un mapa de la
     * superficie de ataque. Con el toggle apagado las rutas no existen —lo
     * apaga `ApiDocsServiceProvider::register()`, antes de que Scramble las
     * registre—, y encendida siguen detrás del gate `viewApiDocs`.
     */
    'docs' => [
        'enabled' => (bool) env('API_DOCS', false),
    ],

    /*
     * Peticiones por minuto de cada limiter nombrado. Los registra
     * `AppServiceProvider::configureApiRateLimiters()` y se aplican con
     * `throttle:{nombre}`:
     *
     *   api          · el del grupo `api`, por usuario (o IP si es anónimo).
     *   api-auth     · login, registro, magic link, refresco de token. Por IP,
     *                  porque el atacante todavía no tiene usuario (R28).
     *   api-uploads  · subidas de archivo, por usuario. Caras en CPU y en disco.
     */
    'limiters' => [
        'api' => 60,
        'api-auth' => 5,
        'api-uploads' => 30,
    ],

];
