<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Parámetros del módulo Mx
    |--------------------------------------------------------------------------
    |
    | Esto NO es `config/kore-app.php`: allí vive el toggle (`MX_ENABLED`, «¿está
    | el módulo encendido?») y aquí las cifras («¿cómo se comporta cuando lo
    | está?»). Mismo reparto que `devices` y `files`, y por eso el check R11 no
    | mira este archivo: no declara capacidades.
    |
    */

    /*
     * Caché de `App\Modules\Mx\Support\PostalCodes::lookup()`.
     *
     * El catálogo de SEPOMEX cambia cuatro veces al año como mucho, así que un
     * día de TTL es conservador. `store` a null usa el default de la aplicación;
     * un derivado con Redis puede mandarlo a un store aparte para poder vaciar
     * el catálogo sin tocar el resto de la caché.
     */
    'cache' => [
        'ttl' => (int) env('MX_CACHE_TTL', 86400),
        'store' => env('MX_CACHE_STORE'),
        'prefix' => 'mx:postal-code:',
    ],

    /*
     * Importación del CSV de SEPOMEX (`mx:sepomex:import`).
     *
     * `chunk_size` es cuántas filas van en cada `upsert`. Mil es el equilibrio
     * habitual: por debajo se pagan demasiados viajes a la base y por encima
     * algunos drivers se quejan del número de placeholders de la sentencia
     * (el CSV completo son ~145 000 filas y siete columnas por fila).
     */
    'import' => [
        'chunk_size' => 1000,
    ],

];
