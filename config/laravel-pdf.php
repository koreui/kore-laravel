<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| spatie/laravel-pdf — configuración del paquete
|--------------------------------------------------------------------------
|
| Este archivo es del PAQUETE (su nombre lo fija `PdfServiceProvider`, que
| llama a `hasConfigFile('laravel-pdf')`), no del boilerplate. Los parámetros
| propios del módulo Pdf —logo, pie, marca de agua, papel y márgenes por
| defecto— viven en `config/kore-pdf.php`, igual que los del contrato de la API
| viven en `config/kore-api.php`.
|
| **Está publicado a medias y es a propósito.** `PackageServiceProvider` hace
| `mergeConfigFrom()`, que es un `array_merge` de PRIMER nivel: las claves que
| no estén aquí las sigue poniendo el paquete con sus valores de fábrica. Así
| que aquí sólo se escribe lo que el boilerplate decide —el driver y cómo se
| alcanza Gotenberg— y las cinco secciones de los drivers que no usamos
| (browsershot, cloudflare, dompdf, weasyprint, chrome), la caché, el job y el
| encrypter se quedan en vendor, donde se actualizan solas.
|
| Contrapartida, escrita para que nadie la descubra depurando: una clave NUEVA
| dentro de `gotenberg` que añada una versión futura del paquete NO llegará
| aquí, porque el merge no baja al segundo nivel. Al actualizar el paquete,
| compara: `diff -w vendor/spatie/laravel-pdf/config/laravel-pdf.php
| config/laravel-pdf.php`.
|
| Ver `docs/modules/pdf.md`.
|
*/

return [

    /*
     * Gotenberg y no browsershot (el default del paquete).
     *
     * Browsershot necesita Node, Puppeteer y un Chromium instalados **en el
     * mismo contenedor que PHP**: la imagen de producción del boilerplate pesa
     * lo que pesa justamente porque no los lleva. Gotenberg es un servicio
     * aparte, con su propio ciclo de vida, que se levanta con el perfil `pdf`
     * de `docker-compose.prod.yml` y se apaga cuando el proyecto no emite
     * documentos.
     *
     * Sigue siendo una variable de entorno: un derivado que prefiera dompdf
     * (PHP puro, sin servicio) pone `LARAVEL_PDF_DRIVER=dompdf` y hace
     * `composer require dompdf/dompdf`.
     */
    'driver' => env('LARAVEL_PDF_DRIVER', 'gotenberg'),

    /*
     * Cómo se alcanza Gotenberg.
     *
     * `127.0.0.1:3000` es el default de desarrollo, donde Gotenberg corre en un
     * contenedor suelto publicado sólo en loopback:
     *
     *   docker run --rm -p 127.0.0.1:3000:3000 gotenberg/gotenberg:8
     *
     * En producción la aplicación y Gotenberg comparten la red de compose y no
     * hay puerto publicado, así que el `.env` de producción pone
     * `GOTENBERG_URL=http://gotenberg:3000` (el nombre del servicio).
     *
     * `username`/`password` sólo hacen falta si Gotenberg queda detrás de un
     * proxy con Basic Auth. En la red interna de compose no está expuesto, así
     * que lo normal es dejarlos vacíos.
     */
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:3000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

];
