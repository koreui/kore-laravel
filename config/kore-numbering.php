<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Series de folio — módulo Platform
    |--------------------------------------------------------------------------
    |
    | Cómo se numera y cómo se imprime cada serie de documentos. Lo lee
    | `App\Core\Contracts\NumberSeries` (implementado por
    | `App\Modules\Platform\Support\DatabaseNumberSeries`); el contador vive en
    | la tabla `number_sequences` y aquí sólo está su forma.
    |
    | Este archivo NO declara ningún toggle: Platform está siempre encendido.
    | Recordatorio de R12: un `config/*.php` no puede leer otro.
    |
    */

    /*
     * Lo que hereda una serie que no dice otra cosa. Una serie declarada abajo
     * sólo escribe las claves que cambia; pedir un folio de una serie que no
     * está declarada usa esto entero, que es lo que hace que
     * `next('cualquier-cosa')` funcione sin configurar nada.
     */
    'defaults' => [
        /*
         * Marcas admitidas:
         *
         *   {PREFIX}     · el `prefix` de la serie
         *   {YEAR}       · año de la emisión, cuatro dígitos
         *   {MONTH}      · mes de la emisión, dos dígitos
         *   {SCOPE}      · el scope, o cadena vacía si no lo hay
         *   {NUMBER}     · el contador, sin rellenar
         *   {NUMBER:n}   · el contador rellenado con ceros hasta n dígitos
         *
         * Un `{NUMBER:6}` se desborda con gracia: el folio un millón se imprime
         * con siete dígitos en vez de truncarse. Truncar sería emitir dos folios
         * con el mismo texto.
         */
        'format' => '{PREFIX}-{YEAR}-{NUMBER:6}',

        'prefix' => 'DOC',

        /*
         * Cuándo vuelve el contador a `start`:
         *
         *   never  · un solo contador para toda la vida de la serie
         *   yearly · uno por año natural; el periodo entra en la clave única de
         *            `number_sequences`, así que 2026 y 2027 son dos contadores
         *
         * No hay `monthly` a propósito: nadie lo ha pedido todavía y un valor de
         * configuración que no usa nadie es un toggle fantasma (el mismo
         * criterio de R11). Añadirlo es una línea en `SeriesDefinition`.
         */
        'reset' => 'yearly',

        /*
         * Primer número de la serie. Un derivado que migra desde un sistema
         * anterior lo pone en el folio siguiente al último que emitió allí, y
         * la numeración continúa sin un salto.
         */
        'start' => 1,
    ],

    /*
     * Series declaradas. La clave es el nombre con el que se piden
     * (`next('receipt')`).
     *
     * `receipt` viene de fábrica porque es el caso que originó el patrón —los
     * recibos de Notarium, con su folio correlativo por serie— y porque una
     * lista de ejemplo vacía no enseña la forma de una entrada.
     */
    'series' => [
        'receipt' => [
            'prefix' => 'REC',
            'reset' => 'yearly',
        ],
    ],

];
