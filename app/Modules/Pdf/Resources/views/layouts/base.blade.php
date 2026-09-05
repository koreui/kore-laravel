@php
    /**
     * Tema base de los documentos PDF del boilerplate.
     *
     * **Una sola hoja para las dos salidas.** La misma vista se sirve en el
     * navegador (vista previa) y se le manda a Gotenberg para convertirla. Lo
     * único que cambia es `$paged`:
     *
     *   - `$paged = true`  → vista previa: fondo gris y la caja con aspecto de
     *     papel, para revisar la maqueta sin descargar nada.
     *   - `$paged = false` → lo que se convierte: Chromium pagina con las mismas
     *     reglas `@page`, sin nada del cromo del visor.
     *
     * En cuanto la vista previa y el PDF sean dos plantillas distintas, lo que
     * se revisa en pantalla deja de ser lo que se entrega. Por eso el CSS del
     * contenido es idéntico en los dos casos.
     *
     * **El cromo (pie, marca de agua, código, numeración) NO usa
     * `->headerView()` / `->footerView()` de spatie/laravel-pdf**: eso lo
     * resolvería sólo Gotenberg, y la vista previa dejaría de enseñar lo que se
     * entrega. Se hace con los dos mecanismos que el navegador y Chromium
     * respetan por igual, y que explica `pdf::partials.chrome`.
     *
     * **Todo el CSS va en línea.** Gotenberg corre en su propio contenedor y no
     * alcanza los assets de Vite; una hoja de estilos enlazada llegaría vacía y
     * el PDF saldría sin maquetar, en silencio. Las imágenes, por lo mismo, van
     * embebidas (`App\Core\Support\PdfImage`).
     *
     * Variables:
     *   $brand      (App\Core\Data\PdfBrandData) logos, pie, código, marca de agua.
     *   $title      (string) título del documento: pestaña del navegador y `<h1>`.
     *   $paged      (bool)   si se está viendo en el navegador.
     *   $pageFormat (string) tamaño de papel para `@page` («A4», «Letter»…).
     *                        Lo inyecta `GotenbergPdfRenderer`; si no viene,
     *                        manda `kore-pdf.format`.
     *
     * Un documento propio extiende esta hoja:
     *
     *   @extends('pdf::layouts.base')
     *   @section('documento') ... @endsection
     *
     * Ver `docs/modules/pdf.md`.
     */
    $brand = $brand ?? new \App\Core\Data\PdfBrandData;
    $paged = $paged ?? false;
    $title = $title ?? config('app.name');
    $pageFormat = $pageFormat
        ?? \App\Core\Enums\PdfPaperFormat::tryFrom(mb_strtolower((string) config('kore-pdf.format', 'a4')))?->cssSize()
        ?? 'A4';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @verbatim
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #1f2937;
            background: #fff;
        }

        /* ── Cromo repetido en TODAS las hojas ───────────────────────────────
           `position: fixed` es lo que repite el pie y la marca de agua hoja a
           hoja, y lo respetan igual el navegador y Chromium. Viven en el carril
           que reserva el margen inferior del papel: si ese margen se recorta,
           el contenido los pisa. */
        .pie {
            position: fixed; bottom: 8mm; left: 0; right: 0;
            text-align: center; font-size: 8.5px; color: #4b5563; line-height: 1.35;
        }
        .pie__linea { display: block; }

        /* Anclada al centro sobre su propio eje: rotar un bloque de ancho
           completo desplaza el texto y cada motor lo recorta en otro sitio. */
        .marca-agua {
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-38deg);
            transform-origin: center center;
            white-space: nowrap;
            font-size: 56px; font-weight: 800; letter-spacing: .1em;
            color: rgba(17, 24, 39, .08);
            /* ENCIMA del contenido, no debajo: una marca de agua que una tabla
               con relleno tapa no marca nada. `pointer-events: none` es para la
               vista previa — sin él la capa de encima se come la selección. */
            z-index: 5;
            pointer-events: none;
        }
        /* El contenido se queda en su propia capa, por debajo de la marca. */
        .documento, .encabezado { position: relative; z-index: 1; }

        /* ── Encabezado ──────────────────────────────────────────────────── */
        .encabezado {
            display: flex; align-items: center; gap: 14px;
            border-bottom: 2px solid #111827;
            padding-bottom: 10px; margin-bottom: 14px;
        }
        .encabezado__logo { max-width: 46mm; max-height: 20mm; object-fit: contain; }
        .encabezado__titulo { flex: 1; min-width: 0; }
        .encabezado__titulo h1 { margin: 0; font-size: 18px; color: #111827; }
        .encabezado__titulo p { margin: 3px 0 0; font-size: 9px; color: #6b7280; }
        .encabezado__secundario { margin-left: auto; }

        /* ── Piezas de contenido ─────────────────────────────────────────── */
        /* break-after: avoid → un título nunca queda huérfano al pie de página:
           viaja con el contenido que le sigue a la página siguiente. */
        .seccion { margin: 0 0 14px; }
        .seccion__titulo {
            background: #111827; color: #fff;
            font-size: 11.5px; font-weight: 700; letter-spacing: .02em;
            text-transform: uppercase; text-align: center;
            padding: 4px 8px; margin: 0 0 8px;
            break-after: avoid; break-inside: avoid;
        }

        .campos { display: block; }
        .campo {
            display: grid; grid-template-columns: 32% 1fr;
            border: 1px solid #9ca3af;
            margin-top: -1px;          /* dos filas contiguas comparten la línea */
            break-inside: avoid;
        }
        .campo__etiqueta {
            display: flex; align-items: center;
            background: #eef0f2; color: #111827;
            font-size: 9.5px; font-weight: 700;
            padding: 3px 6px; border-right: 1px solid #9ca3af;
        }
        .campo__valor { padding: 3px 6px; }

        /* Una tabla que se parte pierde su <thead> en la continuación, así que
           el encabezado se repite: `display: table-header-group` es lo que hacen
           los dos motores. */
        .tabla { width: 100%; border-collapse: collapse; font-size: 10px; }
        .tabla thead { display: table-header-group; }
        .tabla th, .tabla td { border: 1px solid #9ca3af; padding: 4px 6px; }
        .tabla th { background: #eef0f2; font-weight: 700; text-align: left; }
        .tabla tbody tr { break-inside: avoid; }
        .tabla td.num { text-align: right; font-variant-numeric: tabular-nums; }

        .nota { font-size: 9.5px; color: #6b7280; margin: 8px 0 0; }

        /* Saltos de página explícitos, para el documento que los pide. */
        .salto-antes { break-before: page; }
        .salto-despues { break-after: page; }
        @endverbatim

        /* ── Papel y margin boxes ─────────────────────────────────────────
           El código del documento y la numeración van en margin boxes y no en
           un `fixed` porque son una línea de texto cada uno: los margin boxes
           salen fuera del área de contenido sin robarle sitio.

           El código se interpola con Blade y no con una custom property: las
           variables CSS NO llegan a los margin boxes.

           La numeración va sin palabras («3 / 12») a propósito: el contenido de
           un margin box no se puede componer con `__()` sin partir la frase en
           dos claves sueltas —«Página» y «de»— que ningún traductor sabe
           colocar. */
        @page {
            size: {{ $pageFormat }};
            margin: 18mm 16mm 26mm;

            @bottom-center {
                content: counter(page) " / " counter(pages);
                font-size: 8px;
                color: #9ca3af;
            }
@if($brand->documentCode !== null)
            @top-right {
                content: "{{ $brand->documentCode }}";
                font-size: 8px;
                font-weight: 700;
                color: #374151;
            }
@endif
        }

@if($paged)
        /* Sólo en la vista previa del navegador: fondo gris y aspecto de papel.
           Gotenberg nunca ve este bloque, porque recibe la hoja con
           `$paged = false`. */
        body { background: #525659; padding: 16px 0; }
        .hoja {
            position: relative;
            background: #fff;
            width: 210mm; min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 16mm 26mm;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, .04), 0 10px 28px rgba(0, 0, 0, .4);
        }
        /* Sin paginar no hay «hoja siguiente» que perseguir: el pie va al final
           del papel simulado y la marca de agua se ancla a él. */
        .pie { position: absolute; bottom: 8mm; }
        .marca-agua { position: absolute; }
@endif
    </style>
</head>
<body>
@if($paged)
<div class="hoja">
@endif

    @include('pdf::partials.chrome', ['brand' => $brand])

    <header class="encabezado">
        @if($brand->logoUrl !== null)
            <img class="encabezado__logo" src="{{ $brand->logoUrl }}" alt="">
        @endif
        <div class="encabezado__titulo">
            <h1>{{ $title }}</h1>
            @hasSection('subtitulo')
                <p>@yield('subtitulo')</p>
            @endif
        </div>
        @if($brand->secondaryLogoUrl !== null)
            <img class="encabezado__logo encabezado__secundario" src="{{ $brand->secondaryLogoUrl }}" alt="">
        @endif
    </header>

    <main class="documento">
        @yield('documento')
    </main>

@if($paged)
</div>
@endif
</body>
</html>
