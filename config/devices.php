<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo Devices — parámetros
    |--------------------------------------------------------------------------
    |
    | Los parámetros del inventario de dispositivos que consumen la API. Ver
    | `docs/modules/devices.md`.
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende el módulo sigue siendo `DEVICES_ENABLED`
    | (`kore-app.devices.enabled`), que es lo que hace que
    | `DevicesModuleServiceProvider` registre rutas, listeners y comando. Aquí
    | sólo vive cómo se comporta el módulo cuando está encendido. Es el mismo
    | reparto que `kore-app.api.enabled` / `config/kore-api.php`.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético), así que aquí no aparece ningún `config('kore-app.…')`.
    |
    */

    /*
     * Versión mínima de cliente que acepta el middleware
     * `App\Modules\Devices\Http\Middleware\EnsureClientVersion` (alias
     * `devices.version`), comparada con `version_compare()` contra la cabecera
     * `X-App-Version`.
     *
     * `0.0.0` deja pasar cualquier versión: el corte se sube el día que una
     * versión antigua deja de ser compatible, no antes. El middleware es
     * opt-in por ruta, así que subir esta cifra sólo afecta a los endpoints que
     * lo declaran.
     */
    'min_app_version' => env('DEVICES_MIN_APP_VERSION', '0.0.0'),

    /*
     * Retención de los dispositivos revocados, en días. `devices:cleanup` borra
     * los que llevan más de esto revocados.
     *
     * No se borran al revocarlos a propósito: la fila revocada es lo que
     * permite responder «¿desde qué dispositivo se entró?» durante una
     * investigación. Pasado el plazo deja de ser auditoría y pasa a ser un dato
     * personal guardado sin motivo.
     */
    'prune_after_days' => 90,

    /*
     * Inactividad, en días, a partir de la cual `devices:cleanup` da un
     * dispositivo por abandonado y lo revoca (borrando además su token de
     * Sanctum). Es más largo que `prune_after_days` porque son dos relojes
     * distintos: primero se revoca por silencio, y sólo después empieza a
     * correr la retención.
     */
    'stale_after_days' => 180,

    /*
     * Plataformas que la API acepta al registrar un dispositivo. Es una lista
     * blanca: tiene que ser un subconjunto de los `value` de
     * `App\Modules\Devices\Enums\Platform`, y `DevicesConfigTest` lo verifica.
     *
     * Un derivado que sólo tenga app móvil la recorta a `['ios', 'android']` y
     * con eso deja de registrar clientes web o de consola.
     */
    'platforms' => ['ios', 'android', 'web', 'cli'],

];
