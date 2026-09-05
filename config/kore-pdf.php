<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo Pdf — la marca y el papel de los documentos
    |--------------------------------------------------------------------------
    |
    | Los parámetros del módulo `App\Modules\Pdf`: qué logos lleva una hoja, qué
    | dice su pie, si va sellada y sobre qué papel se imprime. Los lee
    | `App\Modules\Pdf\Support\PdfBrand` y `GotenbergPdfRenderer`.
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende el módulo sigue siendo `PDF_ENABLED` (`kore-app.pdf.enabled`).
    | Aquí sólo vive cómo se ven los documentos cuando está encendido — el mismo
    | reparto que `config/kore-api.php` hace con `API_ENABLED`.
    |
    | Tampoco es `config/laravel-pdf.php`, que es del paquete (driver y URL de
    | Gotenberg). Separarlos evita que una actualización de spatie/laravel-pdf
    | pise decisiones del boilerplate.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético). Nada de aquí consulta `kore-app` ni `laravel-pdf`.
    |
    | Ver `docs/modules/pdf.md`.
    |
    */

    /*
     * Logo principal del encabezado, como ruta RELATIVA a `public/`.
     *
     * Relativa y no absoluta para que cambiar el logo sea reemplazar un
     * archivo, sin tocar código ni desplegar. `null` (el default) = sin logo:
     * el boilerplate no trae ninguno, y un derivado pone
     * `PDF_LOGO=img/logo.png`.
     *
     * Se imprime EMBEBIDO como `data:` URI, nunca enlazado — ver
     * `App\Core\Support\PdfImage`. Si el archivo no existe, la hoja sale sin
     * logo en lugar de con una imagen rota.
     */
    'logo' => env('PDF_LOGO'),

    /*
     * Un segundo logo, para los documentos que llevan dos marcas (el de la
     * aplicación y el del cliente, una marca blanca, un sello de certificación).
     * Misma forma y mismas reglas que `logo`.
     */
    'secondary_logo' => env('PDF_SECONDARY_LOGO'),

    /*
     * Las líneas del pie, repetidas en TODAS las hojas.
     *
     * Es un array y no una cadena con `\n` porque cada línea se pinta en su
     * propio `<span>` y el interlineado lo pone el CSS. Vacío = sin pie, y la
     * hoja recupera el espacio.
     *
     * Un array no cabe en una variable de entorno, así que esto se edita aquí
     * (o en el `config/kore-pdf.php` del proyecto derivado), no en el `.env`.
     */
    'footer_lines' => [
        // 'Razón Social S.A. de C.V. · RFC XAXX010101000',
        // 'Calle 123, Ciudad · contacto@ejemplo.mx · +52 55 0000 0000',
    ],

    /*
     * Texto de la marca de agua diagonal. `null` = sin marca de agua.
     *
     * Es el texto disponible, no «va puesta»: la marca de agua **se pide** al
     * generar el documento (`PdfBrand::default(withWatermark: true)`). El mismo
     * reporte se descarga limpio para entregarlo y sellado para que circule
     * internamente, y ninguna de las dos es «la buena».
     */
    'watermark' => env('PDF_WATERMARK'),

    /*
     * Papel por defecto: uno de los `value` de `App\Core\Enums\PdfPaperFormat`
     * (`a4`, `letter`, `legal`).
     *
     * Un documento puede pedir otro con `PdfOptionsData::$format`; esto es lo
     * que se usa cuando no opina, que es casi siempre.
     */
    'format' => env('PDF_FORMAT', 'a4'),

    /*
     * Márgenes por defecto, en MILÍMETROS.
     *
     * `bottom` es mucho más grande que el resto y no es un descuido: ése es el
     * carril del cromo —el pie, el código del documento y la numeración—, que
     * va en `position: fixed` y en los margin boxes de `@page`. Reservarlo aquí
     * es lo único que impide que el contenido lo pise. Si se recorta, el texto
     * de la última línea acaba debajo del pie.
     */
    'margins' => [
        'top' => 18.0,
        'right' => 16.0,
        'bottom' => 26.0,
        'left' => 16.0,
    ],

];
