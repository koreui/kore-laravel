@php
    /**
     * El cromo del documento: lo que se repite en TODAS las hojas.
     *
     * Se apoya en dos mecanismos que el navegador (vista previa) y Chromium
     * (el PDF que entrega Gotenberg) respetan por igual:
     *
     *   - `position: fixed` para el pie y la marca de agua, que son varias
     *     líneas o texto rotado. Los dos motores los repiten hoja a hoja.
     *   - los margin boxes de `@page` para el código del documento y la
     *     numeración, que son una línea de texto y viven en el CSS de
     *     `pdf::layouts.base`.
     *
     * **NO se usa `->footerView()` de spatie/laravel-pdf**: el paquete lo manda
     * como un archivo aparte que sólo compone el convertidor, así que la vista
     * previa dejaría de enseñar lo que se entrega — que es justo lo que este
     * tema existe para garantizar.
     *
     * $brand (App\Core\Data\PdfBrandData).
     */
@endphp

@if($brand->watermark !== null)
    <div class="marca-agua" aria-hidden="true">{{ $brand->watermark }}</div>
@endif

@if($brand->hasFooter())
    <footer class="pie">
        @foreach($brand->footerLines as $linea)
            <span class="pie__linea">{{ $linea }}</span>
        @endforeach
    </footer>
@endif
