# Módulo Mx — utilidades de México

**TL;DR**: dos cosas que casi todo proyecto mexicano acaba escribiendo mal por su
cuenta —el catálogo de códigos postales de SEPOMEX y el importe en letra— detrás
del toggle `MX_ENABLED`. Con el toggle apagado no hay rutas, ni componente
Livewire, ni comando; las tablas se migran igual y nacen vacías, porque el
catálogo es un dato de un tercero que no viaja en el repositorio.

- Toggle: `MX_ENABLED` (default `false`) en `config/kore-app.php`
- Parámetros: `config/mx.php` (TTL de caché, tamaño del lote de importación)
- Provider: `App\Modules\Mx\Providers\MxModuleServiceProvider`
- Tablas: `mx_states` (32 filas, las siembra el módulo) y `mx_postal_codes`
  (~145 000 filas, las trae `mx:sepomex:import`)

## Por qué es un módulo opcional y no parte del núcleo

El boilerplate no es mexicano: es un boilerplate. «Código postal → colonias» y
«PESOS 00/100 M.N.» son convenciones de **un** país, y meterlas en `App\Core`
obligaría a un proyecto argentino a arrastrar dos tablas que nunca consulta y a
un contrato que no le dice nada.

Al mismo tiempo, quien sí trabaja en México los escribe **siempre**, y casi
siempre mal: los centavos se pierden por el redondeo de un `float`, «MIL» se
escribe sin el «UN» que impide alterarlo, y el CSV de SEPOMEX se importa sin
convertir de ISO-8859-1 y deja «Ãlvaro Obregón» impreso en una escritura. Un
módulo opt-in resuelve las dos mitades: existe para quien lo enciende y no pesa
para quien no.

Por eso tampoco hay contrato en `App\Core\Contracts`: **nada del núcleo consume
Mx**. Los contratos de Core existen para que un módulo pueda llamar a otro sin
conocerlo (`FileStore`, `PdfRenderer`); aquí no hay ningún llamador en el núcleo,
así que un contrato sería una interfaz con un solo implementador y ningún
cliente. Lo que el módulo exporta son dos clases públicas y documentadas.
Cómo llegar a ellas desde otro módulo, más abajo.

## Toggle

```env
MX_ENABLED=false
# MX_CACHE_TTL=86400
```

Con `MX_ENABLED=false` el provider hace `return` temprano y no registra:

- las rutas `api/v1/mx/*`,
- el componente Livewire `mx.postal-code-field`,
- el comando `mx:sepomex:import`,
- las traducciones del módulo.

**El comando también está detrás del toggle**, y es una decisión: la tabla
existe siempre, pero sin el módulo encendido nadie la consulta, así que
importar el catálogo dejaría 145 000 filas inertes y un `mx:sepomex:import` en
el cron que ya no significa nada. Encender `MX_ENABLED` es el paso que da sentido
a importar.

Dos cosas se registran **siempre**, y ninguna es una excepción a R10 sino la
regla de [`../architecture/toggles.md`](../architecture/toggles.md):

- **Las migraciones.** Un toggle apaga rutas y comportamiento, nunca el esquema:
  si dependiera del `.env`, dos instalaciones del mismo commit tendrían bases
  distintas y encender el módulo en producción exigiría migrar a mano con
  tráfico encima.
- **`loadViewsFrom` del espacio `mx::`.** Blade resuelve las etiquetas
  `<x-mx::…>` al **compilar** la plantilla, no al ejecutarla, así que registrar
  el espacio dentro del toggle deja un 500 en cualquier pantalla que las
  mencione (es la cicatriz de `files::`).

## Las tablas

### `mx_states`

| Columna | Tipo | Qué es |
|---------|------|--------|
| `code` | `string(2)`, único | clave SAT/INEGI: `'01'`..`'32'` |
| `name` | `string` | nombre oficial |
| `abbreviation` | `string(5)` | `AGS`, `CDMX`, `TAMPS`... |

`code` es una **cadena** y no un entero: la clave oficial se escribe con el cero
delante y va impresa así en facturas y escrituras. Guardarla como `int` obliga a
rellenarla con `str_pad` en cada sitio que la imprime, y basta olvidarse una vez
para publicar `9` donde el SAT espera `09`.

La abreviatura es de cinco y no de cuatro por Tamaulipas (`TAMPS`), que es la más
larga de las 32.

### `mx_postal_codes`

| Columna | Tipo | Qué es |
|---------|------|--------|
| `postal_code` | `string(5)`, indexado | con sus ceros a la izquierda |
| `settlement` | `string` | la colonia |
| `settlement_type` | `string` | `Colonia`, `Fraccionamiento`, `Pueblo`... |
| `municipality` | `string` | |
| `city` | `string`, nullable | el CSV la deja vacía a menudo |
| `state_code` | `string(2)`, FK → `mx_states.code` | |

Una fila **no** es «un código postal»: es un asentamiento. Un CP tiene entre una
y varias decenas de colonias, todas con el mismo municipio y la misma entidad.

`settlement_type` no es nullable porque forma parte del índice único
`(postal_code, settlement, settlement_type)`, que es lo que hace idempotente la
importación: en MySQL y en SQLite dos filas con `NULL` en una columna del índice
único no chocan entre sí, así que un tipo nulo reabriría la puerta a los
duplicados que el índice viene a cerrar.

La FK apunta a `mx_states.code` y no a su `id`: la clave del SAT es la identidad
natural del catálogo y es la que trae el CSV, así que se ahorra una traducción
por fila en una importación de 145 000.

## Importar el catálogo

El archivo **no viaja en el repositorio**: son unos catorce megas de datos de un
tercero con su propia licencia de uso. Se descarga del portal de Correos de
México (Servicio Postal Mexicano), en su sección de códigos postales, o desde el
mismo catálogo publicado en datos.gob.mx. Aquí no se enlaza un archivo concreto
a propósito: la ruta exacta cambia cada pocos años y un enlace muerto en un doc
es peor que ninguno.

```bash
# Ensayo: cuenta y no escribe nada. Es lo que se corre la primera vez.
php artisan mx:sepomex:import storage/app/CPdescarga.txt --dry-run

# De verdad.
php artisan mx:sepomex:import storage/app/CPdescarga.txt

# O descargándolo.
php artisan mx:sepomex:import --url=https://…/CPdescarga.txt
```

El comando:

1. **Siembra `mx_states`** con `MxStatesSeeder` (las 32 entidades, que sí van en
   el repositorio: son treinta y dos filas de dominio público). Va primero
   porque la FK de `mx_postal_codes` apunta ahí.
2. **Detecta el separador y la codificación.** El archivo oficial va separado por
   `|` y en ISO-8859-1; el mismo catálogo circula convertido a coma y UTF-8. Las
   dos formas entran: el separador se deduce de la cabecera y la codificación se
   detecta leyendo los primeros 64 KB. Convertir a ciegas un archivo ya
   convertido produce «Ãlvaro Obregón», que es el fallo que nadie mira hasta que
   sale impreso.
3. **Repone los ceros de la izquierda.** Varias copias del CSV han pasado por una
   hoja de cálculo que leyó `01000` como el número `1000`.
4. **Escribe con `upsert` en lotes** de `mx.import.chunk_size` (1000) dentro de
   una transacción, deduplicando dentro del lote —SQLite y PostgreSQL rechazan un
   `ON CONFLICT DO UPDATE` que afecte dos veces a la misma fila en la misma
   sentencia, y el catálogo trae asentamientos repetidos—.
5. **Imprime progreso y totales**: importados, repetidos, saltados y el total de
   la tabla.

Correrlo dos veces deja la misma tabla. Lo que **no** hace es borrar: un
asentamiento que SEPOMEX retire sigue ahí, porque una dirección guardada hace
tres años tiene que poder seguir mostrándose. Quien quiera una tabla limpia la
vacía antes de importar.

La cabecera que espera es la del archivo oficial:

```
d_codigo|d_asenta|d_tipo_asenta|D_mnpio|d_estado|d_ciudad|d_CP|c_estado|c_oficina|c_CP|c_tipo_asenta|c_mnpio|id_asenta_cpcons|d_zona|c_cve_ciudad
```

De ahí sólo se guardan seis columnas —`postal_code`, `settlement`,
`settlement_type`, `municipality`, `city` y `state_code`—: las que un formulario
de dirección necesita. El resto son claves internas de SEPOMEX que nadie de
fuera consulta.

## `MontoEnLetras`

```php
use App\Modules\Mx\Support\MontoEnLetras;

(new MontoEnLetras)->format(1234.56);
// UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.

(new MontoEnLetras)->format(15000);
// QUINCE MIL PESOS 00/100 M.N.

(new MontoEnLetras)->format(1, 'PESO');
// UN PESO 00/100 M.N.

(new MontoEnLetras)->format(100, 'DÓLARES', 'USD');
// CIEN DÓLARES 00/100 USD
```

Es **pura**: no toca base de datos, ni configuración, ni reloj. Se instancia con
`new` en un test sin arrancar la aplicación.

### Las tres decisiones de forma

1. **Los centavos van en cifra, `NN/100`**, y `M.N.` («moneda nacional») cierra
   la fórmula. Es la convención notarial y fiscal mexicana: el importe se escribe
   con letra para que no se pueda alterar, y los centavos en cifra porque
   «CINCUENTA Y SEIS CENTAVOS» alarga la línea sin añadir seguridad.
2. **Apócope: «UN» y no «UNO».** El número acompaña a un sustantivo —«UN PESO»,
   «VEINTIÚN MIL»— y ahí el apócope es obligatorio en español.
3. **Los millares llevan siempre su «UN»**: 1 000 es `UN MIL`, no `MIL`. En prosa
   corriente se diría «mil»; en un documento donde el número tiene que ser
   inalterable, dejar el hueco delante de «MIL» es justo lo que se evita.

### Lo que no hace

- **No concuerda en género**: devuelve siempre la forma masculina, porque lo que
  acompaña es una moneda. Quien necesite «UNA UNIDAD» lo envuelve.
- **No declina la moneda**: `$currency` sale tal cual, así que el singular se
  pide pasando `'PESO'`.
- **No acepta negativos** ni importes por encima de 999 999 999 999: los dos
  lanzan `InvalidArgumentException`.

Todo el cálculo va en **centavos enteros**. Restar la parte entera de un float
—`($amount - floor($amount)) * 100`— arrastra el error de representación:
`1234.56 - 1234` son `0.55999999999995`, y un truncado devolvería 55 centavos.

## `PostalCodes`

```php
use App\Modules\Mx\Support\PostalCodes;

$cp = app(PostalCodes::class)->lookup('01000');

$cp?->postalCode;    // '01000'
$cp?->municipality;  // 'Álvaro Obregón'
$cp?->stateCode;     // '09'
$cp?->stateName;     // 'Ciudad de México'  (de mx_states, no del CSV)
$cp?->city;          // 'Ciudad de México' | null
$cp?->settlements;   // [['name' => 'Axotla', 'type' => 'Pueblo'], …] ordenadas por nombre
```

Las colonias viajan como *array shape* (`['name' => …, 'type' => …]`) y no como
un `SettlementData`. Lo natural sería el DTO anidado, pero R8 está escrito como
lista blanca —un DTO sólo puede depender de `App\Core\Data`, `App\Core\Enums`,
`Spatie\LaravelData` y enums— y eso deja fuera al DTO de al lado en el mismo
módulo. Antes que abrir una válvula, la lista va documentada con su forma.

Devuelve `null` cuando el código no está en el catálogo **y** cuando lo que
llega no son cinco dígitos. No rellena con ceros: `'1000'` no es un código postal
al que le falte un cero, es un dato mal copiado, y adivinar convertiría un error
del cliente en una respuesta plausible.

### La caché guarda también los fallos

Un formulario consulta el CP en cuanto el usuario escribe el quinto dígito, y un
bot que prueba códigos inventados haría una consulta por intento. Por eso un CP
que no existe se guarda igual —como `false`, que es lo que distingue «no está en
el catálogo» de «no está en la caché»— con el mismo TTL. `Cache::remember()` no
sirve tal cual: trata `null` como fallo de caché y volvería a consultar cada vez.

TTL y store salen de `config/mx.php`; un derivado con Redis puede mandar el
catálogo a un store aparte para poder vaciarlo sin tocar el resto de la caché.

## API

Dos endpoints, bajo `MX_ENABLED` **y** `API_ENABLED`, con el contrato de R54
(ver [`../guides/api.md`](../guides/api.md)):

| Método | Ruta | Nombre |
|--------|------|--------|
| `GET` | `/api/v1/mx/postal-codes/{postalCode}` | `api.v1.mx.postal-codes.show` |
| `GET` | `/api/v1/mx/amount-in-words?amount=…` | `api.v1.mx.amount-in-words` |

**Los dos son públicos**, y a propósito. El catálogo de SEPOMEX es público:
pedir un token no protegería ningún dato —cualquiera puede descargarse el archivo
entero— y sí rompería el caso de uso que lo justifica, que es autocompletar la
dirección en un formulario de alta, antes de que exista una sesión. El importe en
letra es una función pura de su parámetro. Lo que sí llevan es el `throttle:api`
del grupo `api` y `api.cache:3600`: un bot recorriendo códigos postales sale
caro y un formulario legítimo, gratis.

```http
GET /api/v1/mx/postal-codes/01000
```

```json
{
  "data": {
    "postal_code": "01000",
    "state": { "code": "09", "name": "Ciudad de México" },
    "municipality": "Álvaro Obregón",
    "city": "Ciudad de México",
    "settlements": [
      { "name": "Axotla", "type": "Pueblo" },
      { "name": "San Ángel", "type": "Colonia" }
    ]
  }
}
```

Un código que no esté en el catálogo es un `404` con `error.code = not_found`;
uno que no sean cinco dígitos, un `422` con `validation_failed` y sus `details`.
La segunda distinción importa: un 404 ahí mentiría, porque el recurso no es que
no exista, es que la petición está mal escrita.

```http
GET /api/v1/mx/amount-in-words?amount=1234.56
```

```json
{
  "data": {
    "amount": 1234.56,
    "words": "UN MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N."
  }
}
```

El endpoint sólo acepta `amount`. La moneda y el sufijo son parámetros de la
clase pero **no** del endpoint: son texto libre que saldría literal en la
respuesta, y un endpoint público que devuelve lo que le mandan es un espejo
cómodo para quien quiera hacer pasar por nuestra una cadena suya.

La documentación OpenAPI sale sola con `API_DOCS=true` (Scramble): los dos
controllers llevan `#[Group('México')]`.

## El componente `PostalCodeField`

```blade
<livewire:mx.postal-code-field />
```

Es **embebible**: no tiene ruta ni pantalla propia, así que no aparece en
`tests/e2e/fixtures/access-map.ts` (R52) ni aporta specs E2E (R36 habla de
módulos con pantalla). Se pone dentro del formulario de dirección de quien lo
necesite: pinta el `<x-kore::input>` del código postal y, al resolverse, el
`<x-kore::select>` de colonias.

El formulario padre escucha el evento:

```php
use Livewire\Attributes\On;

#[On('mx-postal-code-resolved')]
public function fillAddress(array $address): void
{
    $this->form->municipality = $address['municipality'];
    $this->form->state = $address['state_name'];
    $this->form->settlement = $address['settlement'];
}
```

El evento lleva `postal_code`, `municipality`, `city`, `state_code`,
`state_name`, `settlements` (los nombres) y `settlement` (la elegida, si sólo
había una). Cuando el usuario cambia el desplegable sale un segundo evento,
`mx-settlement-selected`, con la colonia: el padre que sólo quiera municipio y
estado escucha el primero y ignora éste.

**Por qué un evento y no `$parent`**: el componente no sabe cómo se llaman los
campos del formulario que lo aloja —ni si hay uno—, así que publica lo que ha
averiguado y se desentiende. Un `$parent->…` acoplaría el catálogo de SEPOMEX a
la forma del formulario de cada proyecto.

**Por qué `lookup()` y no `updatedPostalCode()`**: el hook de Livewire se
llamaría así, y un método público de un componente que empieza por un verbo de
escritura es lo que vigila R23 —el check pediría un `authorize()` dentro—. Aquí
no hay nada que autorizar: el catálogo es público y el componente no escribe. El
método se llama por lo que hace y la vista lo dispara con `wire:keyup.debounce`.
Un nombre honesto en vez de una excepción.

## Usarlo desde otro módulo

R5 lo prohíbe: `App\Modules\Billing` **no** puede importar
`App\Modules\Mx\Support\PostalCodes`. Y no hay contrato en Core porque el núcleo
no consume nada de aquí.

El camino, para el derivado que lo necesite, es el mismo que sigue `FileStore`:
declarar el contrato en su propio `App\Core\Contracts` y vincularlo al módulo.

```php
// app/Core/Contracts/PostalCodeDirectory.php  (lo añade el derivado)
namespace App\Core\Contracts;

interface PostalCodeDirectory
{
    /**
     * @return array{postal_code: string, municipality: string, state_name: string}|null
     */
    public function lookup(string $postalCode): ?array;
}
```

El contrato devuelve datos de `Core` —un array de forma conocida, o un DTO de
`App\Core\Data`— y no `PostalCodeData`, que vive en el módulo: si el contrato
tipara sobre él, quien lo consumiera seguiría importando de `Mx`.

La implementación va en `Mx/Support/`, que es donde el boilerplate pone las
implementaciones de contratos:

```php
// app/Modules/Mx/Support/PostalCodeDirectoryFromCatalog.php
final class PostalCodeDirectoryFromCatalog implements PostalCodeDirectory
{
    public function __construct(private readonly PostalCodes $postalCodes) {}

    public function lookup(string $postalCode): ?array
    {
        $found = $this->postalCodes->lookup($postalCode);

        return $found === null ? null : [
            'postal_code' => $found->postalCode,
            'municipality' => $found->municipality,
            'state_name' => $found->stateName,
        ];
    }
}
```

Y el binding, en el `register()` del provider del módulo y **dentro del toggle**,
para que con `MX_ENABLED=false` resolver el contrato lance en vez de devolver un
objeto que consultaría una tabla vacía (mismo criterio que `FileStore`):

```php
public function register(): void
{
    if (! (bool) config('kore-app.mx.enabled', false)) {
        return;
    }

    $this->app->bind(PostalCodeDirectory::class, PostalCodeDirectoryFromCatalog::class);
}
```

Mientras el consumidor sea una pantalla o un comando de la propia aplicación
—no otro módulo—, nada de esto hace falta: se resuelve `PostalCodes` del
contenedor y ya está.

## Tests

- `MxToggleTest` — el toggle apagado no registra nada; el esquema y el espacio de
  vistas sí; encendido aparecen rutas, comando y componente.
- `MxConfigTest` — los parámetros de `config/mx.php`.
- `MontoEnLetrasTest` (Unit) — 33 importes de la tabla del dataset, el redondeo,
  la moneda a medida y los dos rechazos.
- `PostalCodesTest` — hit, miss, entrada inválida, caché y caché negativa.
- `SepomexImportCommandTest` — importación, conversión de codificación, ceros a la
  izquierda, idempotencia, `--dry-run`, `--url` y los cuatro fallos.
- `MxStatesSeederTest` — las 32 entidades, la clave de dos caracteres y la
  idempotencia.
- `MxApiTest` — el envelope, el 404, los 422 y las cabeceras de caché.
- `PostalCodeFieldTest` — el componente Livewire y sus dos eventos.

El fixture de importación es `app/Modules/Mx/Tests/fixtures/sepomex-sample.txt`:
20 filas **inventadas** en el formato del archivo oficial. El catálogo real no se
copia al repositorio.
