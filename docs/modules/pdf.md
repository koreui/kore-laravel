# Módulo Pdf

**TL;DR**: `PDF_ENABLED=true` enciende un módulo que convierte vistas Blade en
PDF con spatie/laravel-pdf y Gotenberg. Los módulos consumidores no lo conocen:
piden `App\Core\Contracts\PdfRenderer` o llaman a `PdfRenderAction`. Con el
toggle apagado no hay binding, ni rutas, ni gate — y no hay tablas que migrar.

## El toggle

| Variable | Default | Qué enciende |
|----------|---------|--------------|
| `PDF_ENABLED` | `false` | Binding de `PdfRenderer`, gate `viewPdfPreview`, rutas `/pdf/preview*` y las traducciones del módulo |

Es opt-in porque el motor es un **servicio aparte**: un contenedor con Chromium
dentro. Encender el toggle sin levantar Gotenberg no falla al desplegar; falla
la primera vez que alguien pide un documento. Con el toggle apagado, quien
resuelva `PdfRenderer` se lleva un `BindingResolutionException` inmediato y con
nombre en vez de un PDF vacío — que es la diferencia entre un error de
configuración y un misterio en producción.

`loadViewsFrom` es la única excepción al «no registra nada», y es la misma del
módulo Docs: Larastan valida `view('pdf::examples.sample')` contra la aplicación
que arranca durante el análisis, y en CI el toggle vale su default. Registrar
dónde viven las vistas no expone nada; sin rutas no hay forma de llegar a ellas.

**No hay migraciones.** El módulo no tiene tablas: los documentos se generan al
vuelo desde datos que ya viven en otro sitio. Guardarlos sería mantener una copia
sincronizada de algo que se puede volver a imprimir. Es la diferencia con
Devices, cuya tabla sí se migra siempre.

## Las dos configuraciones, y por qué son dos

| Archivo | De quién es | Qué guarda |
|---------|-------------|------------|
| `config/laravel-pdf.php` | del **paquete** (spatie/laravel-pdf lo llama así) | el driver (`gotenberg`) y cómo se alcanza (`GOTENBERG_URL`) |
| `config/kore-pdf.php` | del **boilerplate** | la marca: logos, pie, marca de agua, papel y márgenes por defecto |

Separarlos evita que una actualización del paquete pise decisiones del
boilerplate, y es el mismo reparto que hacen `config/scramble.php` y
`config/kore-api.php`. Ninguno lee al otro (R12): quien los cruza es el módulo.

`config/laravel-pdf.php` está publicado **a medias** a propósito.
`PackageServiceProvider` hace `mergeConfigFrom()`, que es un `array_merge` de
primer nivel, así que las claves que no están en el archivo publicado —caché,
job, encrypter y las cinco secciones de los drivers que no usamos— las sigue
poniendo el paquete y se actualizan solas. La contrapartida, escrita para que
nadie la descubra depurando: una clave **nueva dentro de** `gotenberg` no
llegaría, porque el merge no baja al segundo nivel. Al actualizar el paquete:

```bash
diff -w vendor/spatie/laravel-pdf/config/laravel-pdf.php config/laravel-pdf.php
```

### Las claves de `kore-pdf`

| Clave | `.env` | Qué es |
|-------|--------|--------|
| `logo` | `PDF_LOGO` | ruta **relativa a `public/`** del logo del encabezado. `null` = sin logo |
| `secondary_logo` | `PDF_SECONDARY_LOGO` | un segundo logo (cliente, marca blanca, sello) |
| `footer_lines` | — | array de líneas del pie. No cabe en un `.env`: se edita en el archivo |
| `watermark` | `PDF_WATERMARK` | el texto del sello. Estar configurado **no** lo pone (ver abajo) |
| `format` | `PDF_FORMAT` | `a4`, `letter` o `legal` |
| `margins` | — | milímetros. `bottom` es grande a propósito: es el carril del cromo |

## Cómo se genera un PDF

```php
use App\Core\Data\PdfOptionsData;
use App\Modules\Pdf\Actions\PdfRenderAction;
use App\Modules\Pdf\Support\PdfBrand;

// En un controller o componente Livewire del módulo dueño del documento.
$document = $render->handle(
    view: 'facturas::pdf.factura',
    data: ['factura' => $facturaData],       // DTOs y arrays, nunca Eloquent (R30)
    brand: PdfBrand::default('FA-001'),
    options: new PdfOptionsData(filename: 'factura-'.$folio),
);

return response($document->contents, 200, [
    'Content-Type' => 'application/pdf',
    'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
]);
```

Las piezas, y qué hace cada una:

- **`App\Core\Contracts\PdfRenderer`** — la frontera. `fromView($view, $data,
  $options): PdfDocumentData`. Un módulo que emite documentos depende de esto y
  no de `App\Modules\Pdf` (R5), así que cambiar de motor es cambiar un binding.
- **`PdfRenderAction`** — el caso de uso, y la convención: mete `brand` en los
  datos de la vista (para que el tema base lo encuentre sin que cada módulo se
  acuerde) y fija `paged = false` (lo que se está generando es el PDF, no la
  vista previa).
- **`GotenbergPdfRenderer`** (`Support/`) — el **único** sitio del proyecto que
  conoce spatie/laravel-pdf. Traduce `PdfOptionsData` a llamadas del builder y
  resuelve contra `kore-pdf` lo que el documento dejó en `null`.
- **`PdfOptionsData`** — papel, orientación, márgenes y nombre del archivo.
  `null` en `format` o `margins` significa «lo que diga la configuración», no
  «nada»: si el DTO trajera valores por defecto, cambiar el papel de toda la
  aplicación dejaría de ser una variable de entorno y pasaría a ser un `grep`.
- **`PdfDocumentData`** — nombre y bytes, con `size()`. No sabe construir una
  `Response`: eso ataría `App\Core\Data` a `Illuminate\Http` y rompería R8. La
  monta el controller, que es quien sabe si el documento se abre o se descarga.

## La marca: `PdfBrandData`, `PdfBrand`, `PdfLogo`, `PdfImage`

`PdfBrandData` (en `Core`) es sólo primitivos: logos ya embebidos, líneas de
pie, código del documento y texto de la marca de agua. Todo primitivo porque la
hoja la escribe un módulo y la marca la arma otro; pasar un modelo sería
importar entre módulos.

`PdfBrand::default($documentCode, $withWatermark)` (en el módulo) la arma desde
`kore-pdf`. Vive en el módulo y no en `Core` porque **lee configuración**, y un
DTO no puede (R8, R19).

Dos decisiones que conviene no deshacer sin querer:

- **El código del documento lo pone quien lo genera, no la configuración.** Es
  una propiedad del formato —«FO-500-REV1»—, no de la aplicación.
- **La marca de agua se pide.** Tenerla configurada no la pone: el mismo
  documento se descarga limpio para entregarlo y sellado para que circule
  internamente, y ninguna de las dos es «la buena». Por eso es un parámetro de
  la llamada y no un ajuste guardado.

### Por qué las imágenes van embebidas (R57)

`App\Core\Support\PdfImage::embedded($ruta)` devuelve un `data:` URI, y todos los
logos del tema pasan por ahí. No es una optimización: es lo único que funciona.

Quien convierte la hoja es Gotenberg, **en su propio contenedor**. Un
`<img src="http://127.0.0.1/img/logo.png">` se lo pide a sí mismo, y la imagen
sale rota **en silencio**: el PDF se genera igual, pesa lo mismo, y lo que
Chromium dibuja en su lugar es el icono de imagen rota. En producción, con la
dirección pública, sí funcionaría — y ésa es la trampa: el fallo aparece sólo en
un entorno.

La segunda razón vale también en producción: los archivos que suben los usuarios
viven en disco privado y se sirven por URL firmada y temporal. Embebida, la hoja
no depende de que el convertidor alcance la aplicación ni de que la firma siga
viva cuando pase a buscarla.

Y sale igual en las dos salidas, que es el punto de todo el tema: **lo que se
revisa en pantalla es lo que se imprime**.

Si el archivo no existe, `PdfImage` devuelve `null` y la hoja pinta el hueco. Un
PDF sin logo es recuperable; una excepción a mitad de la generación, o una
imagen rota delante del cliente, no.

## El tema base

`pdf::layouts.base` es la hoja que extienden todos los documentos:

```blade
@extends('pdf::layouts.base')

@section('subtitulo', __('Factura'))

@section('documento')
    <section class="seccion">
        <h2 class="seccion__titulo">{{ __('Conceptos') }}</h2>
        <table class="tabla">...</table>
    </section>
@endsection
```

Variables que recibe: `$brand` (lo pone `PdfRenderAction`), `$title`, `$paged` y
`$pageFormat`. Clases listas: `.seccion`, `.campo` (rejilla etiqueta/valor),
`.tabla` (con `<thead>` que se repite al partirse), `.nota`, `.salto-antes` y
`.salto-despues`.

Tres decisiones del tema, con su razón:

- **Todo el CSS va en línea.** Gotenberg no alcanza los assets de Vite; una hoja
  de estilos enlazada llegaría vacía y el PDF saldría sin maquetar, en silencio.
  Desde la v2.3.0 lo comprueba **R57** (`kore:arch:check`) sobre las vistas del
  módulo y sobre cualquier `pdf/` dentro de las vistas de otro módulo.
- **El cromo NO usa `->headerView()` / `->footerView()`** de spatie/laravel-pdf.
  El paquete los manda como archivos aparte que sólo compone el convertidor, así
  que la vista previa dejaría de enseñar lo que se entrega. En su lugar,
  `pdf::partials.chrome` usa los dos mecanismos que el navegador y Chromium
  respetan por igual: `position: fixed` para el pie y la marca de agua (varias
  líneas, texto rotado) y los **margin boxes de `@page`** para el código del
  documento y la numeración, que son una línea de texto cada uno.
- **La numeración va sin palabras** («3 / 12»). El contenido de un margin box no
  se puede componer con `__()` sin partir la frase en dos claves sueltas
  —«Página» y «de»— que ningún traductor sabe colocar.

Y el margen inferior de `@page` es el carril del cromo: si se recorta, el
contenido lo pisa.

## La vista previa

Con el toggle encendido, `/pdf/preview` sirve el documento de ejemplo como HTML
y `/pdf/preview/download` lo convierte. **La misma vista**: en cuanto sean dos
plantillas, lo que se revisa en pantalla deja de ser lo que se entrega. Lo único
que cambia es `$paged`, que en el navegador pinta el papel simulado.

Va detrás de `auth` y del gate `viewPdfPreview` (superadmin y administrador),
definido en `PdfModuleServiceProvider` igual que `viewApiDocs` en
`ApiDocsServiceProvider`. No lleva permiso propio en el catálogo a propósito: un
permiso más en la matriz de todos los proyectos derivados por una herramienta de
maquetación no sale a cuenta.

`?watermark=1` la sella, para ver cómo queda.

## Gotenberg

### En local

No hay compose de desarrollo en el repositorio: un contenedor suelto basta.

```bash
docker run --rm -p 127.0.0.1:3000:3000 gotenberg/gotenberg:8
curl -sf http://127.0.0.1:3000/health
```

Publicado **sólo en loopback**: Gotenberg convierte el HTML que le manden, así
que expuesto es un renderizador de páginas gratis para cualquiera.

### En producción

Servicio `gotenberg` de `docker-compose.prod.yml`, bajo `profiles: [pdf]` y sin
puertos publicados:

```bash
docker compose -f docker-compose.prod.yml --profile pdf up -d
```

Y en el `.env`: `PDF_ENABLED=true` y `GOTENBERG_URL=http://gotenberg:3000`, que
es el nombre del servicio en la red interna. Ver
[`../ops/deployment.md`](../ops/deployment.md) § PDF con Gotenberg.

El timeout del lado del servidor es `--api-timeout=60s`; el del cliente lo pone
Laravel Http (30 s por defecto). `config/laravel-pdf.php` **no** tiene una clave
de timeout porque el `GotenbergDriver` de la versión instalada no la lee: un
toggle que nadie lee es una mentira en la documentación (R11).

### DOCX, XLSX y PPTX a PDF

La misma imagen trae LibreOffice, y `gotenberg/gotenberg-php` (instalado) es su
cliente oficial. El boilerplate no lo implementa —una Action sin consumidor es
código muerto—; la receta para un derivado está en
[`../ops/deployment.md`](../ops/deployment.md) § Convertir DOCX, XLSX y PPTX.

## Tests

Ninguno habla con Gotenberg: `Spatie\LaravelPdf\Facades\Pdf::fake()` sustituye al
builder, devuelve un PDF de mentira y guarda con qué vista y qué datos se le
llamó.

```php
$fake = Pdf::fake();

$this->actingAs($admin)->get('/pdf/preview/download')->assertOk();

$fake->assertViewIs('pdf::examples.sample');
$fake->assertViewHas('paged', false);
$fake->assertSee('FO-500-REV1');   // mira el HTML que se habría convertido
```

El módulo va apagado en la suite (`phpunit.xml` fuerza `PDF_ENABLED=false`, por
lo mismo que `DOCS_ENABLED`), así que los tests que lo necesitan lo encienden con
`withEnvironment()` — ver
[`../patterns/test-con-otro-entorno.md`](../patterns/test-con-otro-entorno.md).

En la suite E2E el toggle va **apagado**, y las dos rutas están en
`tests/e2e/fixtures/access-map.ts` esperando 404 para los cinco perfiles (R52).
Encenderlo haría que `/pdf/preview/download` fuera un 200 para el superadmin, y
ese 200 sólo existe con un Gotenberg levantado: la suite entera quedaría colgada
de un servicio externo por una pantalla de maquetación. Un derivado que lo tenga
en su CI cambia esas dos entradas a `superadmin: 200` y el resto a 403.

## Ver también

- [`../guides/exports.md`](../guides/exports.md) — la carpeta `Exports/`: Excel,
  CSV y el PDF como formato de salida
- [`../architecture/toggles.md`](../architecture/toggles.md) — `PDF_ENABLED`
  entre los demás
- [`../ops/deployment.md`](../ops/deployment.md) — el perfil `pdf` en producción
