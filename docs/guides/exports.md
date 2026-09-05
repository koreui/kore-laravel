# Exportar datos: la carpeta `Exports/`

**TL;DR**: `Exports/` es una de las carpetas permitidas de un módulo (R3) y
guarda **la salida hacia fuera de la aplicación**: Excel, CSV, PDF. El
boilerplate no instala ningún paquete de exportación —el CSV nativo cubre el
caso común— y esta guía dice cómo se construye cada formato sin inventarse
capas.

## Qué va en `Exports/` y qué no

`Exports/` es presentación, no dominio: describe **cómo se ve** un conjunto de
datos fuera de la aplicación. Por eso no cabe en `Data/` (que es transporte
entre capas) ni en `Support/` (que son implementaciones de contratos y helpers
del módulo).

| Va en `Exports/` | No va en `Exports/` |
|------------------|---------------------|
| La clase que mapea filas a columnas de un Excel | La consulta que saca las filas → una Action |
| La plantilla de columnas de un CSV | El DTO de una fila → `Data/` |
| La hoja Blade de un PDF → **no**, va en `Resources/views/` | |

Y lo de siempre: la lógica vive en una Action, el controller sólo devuelve la
respuesta (R4). Un `Exports/` con reglas de negocio dentro es el `Services/` que
R3 existe para evitar.

R3 no comprueba qué hay **dentro** de `Exports/` —lo fija el paquete que
instale cada proyecto—, sólo que la carpeta es una de las permitidas. Ver
[`../architecture/module-pattern.md`](../architecture/module-pattern.md).

## CSV sin instalar nada

Es el formato que más se pide y el que menos cuesta: PHP trae `fputcsv` y
Laravel trae `StreamedResponse`. Cero dependencias, memoria constante — el
archivo se escribe mientras se lee la base, no se arma entero en RAM.

**La clase de export**, en `Exports/`, sólo sabe de columnas:

```php
// app/Modules/Users/Exports/UsersCsvExport.php
namespace App\Modules\Users\Exports;

final class UsersCsvExport
{
    /** @return list<string> */
    public function headings(): array
    {
        return [__('Nombre'), __('Correo'), __('Alta')];
    }

    /**
     * Una fila del CSV a partir de un DTO.
     *
     * @return list<string>
     */
    public function map(UserData $user): array
    {
        return [$user->name, $user->email, $user->createdAt->toDateString()];
    }
}
```

**La Action** decide qué se exporta y en qué orden; es lo que se testea:

```php
// app/Modules/Users/Actions/UserExportCsvAction.php
final class UserExportCsvAction extends Action
{
    public function __construct(private readonly UsersCsvExport $export) {}

    /**
     * El CSV entero como cadena.
     *
     * Devuelve texto y no una respuesta: así la misma Action sirve para un
     * adjunto de correo, un archivo en disco o un comando de consola (R19).
     */
    public function handle(UserFiltersData $filters): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM: sin él, Excel en Windows abre los acentos como mojibake. Es el
        // reporte de bug más repetido de cualquier exportación a CSV.
        fwrite($handle, "\u{FEFF}");

        fputcsv($handle, $this->export->headings(), ',', '"', '');

        User::query()
            ->tap($filters->apply(...))
            ->chunkById(500, function ($users) use ($handle): void {
                foreach ($users as $user) {
                    fputcsv($handle, $this->export->map(UserData::from($user)), ',', '"', '');
                }
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
```

**El controller** sólo elige la respuesta:

```php
return response()->streamDownload(
    fn () => print $export->handle($filters),
    'usuarios-'.now()->toDateString().'.csv',
    ['Content-Type' => 'text/csv; charset=UTF-8'],
);
```

Dos cosas que se olvidan y cuestan un reporte de bug cada una:

- **El BOM UTF-8** (`\u{FEFF}`), o Excel en Windows pinta `JosÃ©`.
- **`chunkById` y no `get()`**: una exportación de 200 000 filas con `get()` se
  come la memoria del contenedor, y no en tu máquina sino en producción.

No se usa `league/csv` porque `fputcsv` ya hace esto y una dependencia menos en
el núcleo es una dependencia menos que actualizar en todos los derivados. Un
proyecto que necesite lo que ese paquete aporta de más —RFC 4180 estricto,
lectura, escapes exóticos— lo instala en el derivado.

## Excel con `maatwebsite/excel`, en el derivado

`maatwebsite/excel` **no está en el núcleo** y no debería estarlo: arrastra
PhpSpreadsheet, que es pesado, y la mayoría de los proyectos se apañan con CSV.
Cuando un proyecto sí necesita un `.xlsx` de verdad —varias hojas, formatos de
celda, fórmulas—, lo instala él:

```bash
composer require maatwebsite/excel
```

La clase de export sigue en `Exports/`, implementando los contratos del paquete:

```php
// app/Modules/Reportes/Exports/VentasExport.php
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class VentasExport implements FromCollection, WithHeadings, WithMapping
{
    /** @param Collection<int, VentaData> $ventas */
    public function __construct(private readonly Collection $ventas) {}

    // Recibe los datos ya resueltos: la consulta es de la Action, no del export.
    public function collection(): Collection
    {
        return $this->ventas;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [__('Folio'), __('Cliente'), __('Total')];
    }

    /** @return list<string|float> */
    public function map(mixed $venta): array
    {
        return [$venta->folio, $venta->cliente, $venta->total];
    }
}
```

Y la Action construye el export y devuelve el archivo:

```php
final class VentaExportExcelAction extends Action
{
    public function handle(VentaFiltersData $filters): BinaryFileResponse
    {
        return Excel::download(
            new VentasExport($this->ventas($filters)),
            'ventas-'.now()->toDateString().'.xlsx',
        );
    }
}
```

> `Excel::download()` devuelve una `BinaryFileResponse`, que es de la capa Http.
> Si el export tiene que servir también desde un job o un comando, la Action
> devuelve `Excel::raw(...)` (bytes) y es el controller quien monta la respuesta
> — el mismo reparto que hace `PdfDocumentData`.

El test **no** genera un xlsx:

```php
Excel::fake();

$this->actingAs($admin)->get('/reportes/ventas.xlsx')->assertOk();

Excel::assertDownloaded('ventas-'.now()->toDateString().'.xlsx', function (VentasExport $export): bool {
    return $export->headings() === [__('Folio'), __('Cliente'), __('Total')];
});
```

## PDF: no hay nada que escribir en `Exports/`

Un PDF ya tiene su camino y no pasa por esta carpeta: la hoja es una Blade en
`Resources/views/` que extiende `pdf::layouts.base`, y la conversión la hace
`PdfRenderAction` (o el contrato `App\Core\Contracts\PdfRenderer`). Ver
[`../modules/pdf.md`](../modules/pdf.md).

```php
$document = $render->handle(
    view: 'ventas::pdf.reporte',
    data: ['ventas' => $ventas],
    brand: PdfBrand::default('RP-001'),
    options: new PdfOptionsData(filename: 'reporte-ventas'),
);
```

Lo único que hay que recordar al escribir la hoja: **CSS en línea e imágenes
embebidas**, porque el convertidor corre en otro contenedor. El módulo Pdf lo
explica con el detalle que merece.

## Elegir formato

| Formato | Cuándo | Coste |
|---------|--------|-------|
| **CSV** | listados que alguien va a abrir en una hoja de cálculo o a importar en otro sistema | cero dependencias |
| **Excel** | varias hojas, formatos de celda, fórmulas, columnas anchas | `maatwebsite/excel` en el derivado |
| **PDF** | documentos que se **entregan**: facturas, reportes, contratos | `PDF_ENABLED` + Gotenberg |

La pregunta que decide casi siempre: ¿el destinatario lo va a **leer** o lo va a
**procesar**? Un PDF que alguien tiene que volver a teclear en Excel es el
formato equivocado, y un CSV con el logo de la empresa no existe.

## Ver también

- [`../architecture/module-pattern.md`](../architecture/module-pattern.md) — la
  lista cerrada de carpetas (R3)
- [`../modules/pdf.md`](../modules/pdf.md) — el módulo Pdf entero
- [`crud.md`](crud.md) — de dónde salen los datos que se exportan
