@extends('pdf::layouts.base')

{{--
    Documento de ejemplo del tema base.

    No es decoración: es la referencia ejecutable de lo que el tema sabe pintar
    —portada, rejilla de campos, tabla que puede partirse entre hojas y salto de
    página explícito— y lo que se abre en `/pdf/preview` para revisar la maqueta
    sin descargar nada.

    Los datos son de mentira y llegan desde el controller como arrays planos,
    nunca como modelos Eloquent (R30). Un documento real se escribe igual: se
    extiende `pdf::layouts.base` y se rellena la sección `documento`.

    Ver `docs/modules/pdf.md`.
--}}

@section('subtitulo', __('Hoja de ejemplo del tema base de PDF'))

@section('documento')

    <section class="seccion">
        <h2 class="seccion__titulo">{{ __('Datos del documento') }}</h2>

        <div class="campos">
            @foreach($fields as $label => $value)
                <div class="campo">
                    <div class="campo__etiqueta">{{ $label }}</div>
                    <div class="campo__valor">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <p class="nota">{{ __('Las etiquetas y los valores llegan como arrays planos: la hoja no consulta la base de datos.') }}</p>
    </section>

    <section class="seccion">
        <h2 class="seccion__titulo">{{ __('Conceptos') }}</h2>

        <table class="tabla">
            <thead>
                <tr>
                    <th>{{ __('Concepto') }}</th>
                    <th>{{ __('Cantidad') }}</th>
                    <th>{{ __('Importe') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['concept'] }}</td>
                        <td class="num">{{ $row['quantity'] }}</td>
                        <td class="num">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="nota">{{ __('Una tabla que no cabe en la hoja se parte repitiendo su encabezado.') }}</p>
    </section>

    <section class="seccion salto-antes">
        <h2 class="seccion__titulo">{{ __('Segunda hoja') }}</h2>

        <p>{{ __('Esta sección abre en una hoja nueva porque lleva la clase salto-antes. El pie, la marca de agua, el código del documento y la numeración se repiten aquí sin escribir nada más: son el cromo del tema.') }}</p>

        <p class="nota">{{ __('En la vista previa del navegador la paginación es aproximada; el corte definitivo lo decide Chromium al convertir.') }}</p>
    </section>

@endsection
