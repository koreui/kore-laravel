<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | KoreLaravel toggles
    |--------------------------------------------------------------------------
    |
    | Boolean switches to enable/disable optional capabilities of the
    | boilerplate. Drive everything from .env so each environment can opt
    | in/out without code changes. For per-user / per-team gradual rollouts
    | use Laravel Pennant features in app/Providers/AppServiceProvider.
    |
    | REGLA: aquí sólo viven toggles que algún archivo lee de verdad. Si una
    | capacidad no está instalada (Reverb, Octane, Scout...) no lleva toggle:
    | es un módulo opcional que se instala bajo demanda. Los toggles fantasma
    | mienten sobre lo que el boilerplate hace.
    |
    */

    'api' => [
        'enabled' => (bool) env('API_ENABLED', true),
    ],

    'tenancy' => [
        'enabled' => (bool) env('TENANCY_ENABLED', false),
    ],

    // Backups (spatie/laravel-backup). Opt-in como tenancy: el provider del
    // paquete está en `dont-discover` y sólo lo registra BackupServiceProvider
    // cuando esto es true. Producción lo enciende en su .env.
    'backup' => [
        'enabled' => (bool) env('BACKUP_ENABLED', false),
    ],

    // Visor de docs/ en /docs; pensado para local, apágalo en producción. Con
    // el toggle apagado el módulo Docs no registra ninguna ruta y /docs es un
    // 404 como cualquier otra ruta inexistente.
    'docs' => [
        'enabled' => (bool) env('DOCS_ENABLED', false),
    ],

    // Dispositivos que consumen la API (módulo Devices). Opt-in: un proyecto
    // derivado sin app móvil ni CLI no necesita el inventario. Con el toggle
    // apagado no hay rutas, ni listeners de los eventos de Auth, ni comando de
    // limpieza, ni entrada en el scheduler. La TABLA sí se migra siempre:
    // un toggle apaga rutas y comportamiento, nunca el esquema (mismo criterio
    // que las passkeys). Ver docs/modules/devices.md.
    'devices' => [
        'enabled' => (bool) env('DEVICES_ENABLED', false),
    ],

    // Generación de PDF (módulo Pdf, sobre spatie/laravel-pdf + Gotenberg).
    // Opt-in: el motor es un SERVICIO aparte —un contenedor con Chromium
    // dentro—, así que encenderlo sin levantarlo deja los documentos rotos en
    // producción y no en el momento del deploy. Con el toggle apagado no hay
    // rutas, ni gate, ni binding de `App\Core\Contracts\PdfRenderer`: quien lo
    // resuelva se lleva un error con nombre en vez de un PDF vacío. El módulo
    // no tiene tablas, así que aquí no hay nada que migrar.
    // La marca y el papel viven en `config/kore-pdf.php`; el driver y la URL de
    // Gotenberg, en `config/laravel-pdf.php`. Ver docs/modules/pdf.md.
    'pdf' => [
        'enabled' => (bool) env('PDF_ENABLED', false),
    ],

    // Almacenamiento de archivos con versionado por slot (módulo Files, sobre
    // spatie/laravel-medialibrary). Opt-in: un proyecto derivado que no sube
    // ficheros no necesita el contrato ni la ruta firmada. Con el toggle
    // apagado no hay binding de `App\Core\Contracts\FileStore` —resolverlo
    // lanza, que es la respuesta correcta y no un fallo a medias—, ni ruta
    // `files.serve`, ni listeners, ni comando. La TABLA `media` sí se migra
    // siempre: un toggle apaga rutas y comportamiento, nunca el esquema (mismo
    // criterio que devices y que las passkeys). Ver docs/modules/files.md.
    'files' => [
        'enabled' => (bool) env('FILES_ENABLED', false),
    ],

    // Webhooks salientes con firma HMAC y outbox con reintentos (módulo
    // Webhooks). Opt-in: un proyecto derivado que no integra con nadie no
    // necesita ni la pantalla ni el barrido del scheduler. Con el toggle
    // apagado no hay binding de `App\Core\Contracts\WebhookPublisher`
    // —resolverlo lanza, que es la respuesta correcta y no un envío
    // silencioso—, ni rutas `/webhooks`, ni listeners, ni alias
    // `webhook.signed`, ni los comandos `webhooks:*`. Las TABLAS
    // `webhook_endpoints` y `webhook_deliveries` sí se migran siempre: un
    // toggle apaga rutas y comportamiento, nunca el esquema (mismo criterio que
    // devices, files y las passkeys). Ver docs/modules/webhooks.md.
    'webhooks' => [
        'enabled' => (bool) env('WEBHOOKS_ENABLED', false),
    ],

    'socialite' => [
        'google' => (bool) env('SOCIAL_GOOGLE', false),
        'github' => (bool) env('SOCIAL_GITHUB', false),
    ],

    'auth' => [
        'two_factor' => (bool) env('AUTH_2FA_ENABLED', true),
        'magic_links' => (bool) env('AUTH_MAGIC_LINKS', true),
        'social_login' => (bool) env('AUTH_SOCIAL_LOGIN', false),

        // Passkeys (WebAuthn) vía Fortify + laravel/passkeys. Encendido por
        // defecto: es la dirección a la que va la industria y no cuesta nada
        // mientras nadie registre una. Sus lectores son
        // `FortifyServiceProvider::register()` (añade o quita la feature) y
        // `Auth/Routes/web.php` (la pantalla `/user/passkeys`).
        'passkeys' => (bool) env('AUTH_PASSKEYS', true),
    ],

    /*
     * Harness de la suite E2E (Playwright): las rutas `/__e2e__/*` con las que
     * las pruebas siembran usuarios, entran como un rol y leen el buzón de
     * correo sin recorrer cinco pantallas para llegar a la sexta.
     *
     * Es un backdoor a propósito, así que el flag NO basta: `HarnessGuard`
     * exige además un entorno permitido (`e2e`, `testing`, `local`) y una base
     * de datos de pruebas. Quien lo enciende es `.env.e2e`, y sólo `.env.e2e`:
     * en `.env`, en `.env.example` y en producción va en false.
     *
     * Ver `docs/modules/e2e.md`.
     */
    'e2e' => [
        'harness' => (bool) env('E2E_HARNESS', false),
    ],

];
