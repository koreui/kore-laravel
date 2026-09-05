# Módulo Platform — ajustes, folios y features

**TL;DR**: tres piezas de infraestructura que toda instalación necesita y que
ningún módulo de negocio debería reinventar: **Settings** (configuración en base
de datos con fallback al archivo), **Numbering** (folios correlativos sin huecos
ni duplicados) y **Features por instalación** (qué módulos incluye la licencia).
Los contratos viven en `App\Core\Contracts`; la implementación, las tablas y la
pantalla, en `app/Modules/Platform/`. **No tiene toggle: está siempre
encendido.**

## Por qué no hay toggle

Devices, Pdf y Files lo tienen porque cada uno cobra un precio que un derivado
puede no querer pagar: un contenedor con Chromium, media-library instalado, un
inventario que mantener. Platform no cobra ninguno —dos tablas pequeñas y tres
contratos— y en cambio perdería algo: si `Settings` pudiera no estar bindeado,
cada consumidor tendría que preguntar antes de resolverlo y el layout, que pinta
el nombre de la organización en todas las pantallas, llevaría un `@if` alrededor
para siempre. **Un contrato que a veces no existe no es una frontera, es una
condición.** Por eso `PlatformModuleServiceProvider` no tiene early return y es
el mismo trato que reciben Users y Auth.

La decisión de dónde vive cada cosa se tomó igual: el roadmap decía
«Core/Settings», pero `App\Core` no tiene tablas ni pantallas. Lo que va a Core
es lo que otros módulos necesitan **importar** —los contratos, los DTOs y el
trait—; lo que va al módulo es lo que hay que **implementar**.

```
app/Core/
├── Contracts/
│   ├── Settings.php                # get · set · all · forget
│   ├── NumberSeries.php            # next · peek
│   └── InstallationFeatures.php    # enabled · all
├── Data/
│   ├── OrganizationData.php        # la organización, para vistas y PDF
│   └── IssuedNumberData.php        # un folio emitido
└── Concerns/HasIssuedNumber.php    # trait opt-in para un modelo con folio

app/Modules/Platform/
├── Actions/{SettingUpdateAction, SettingResetAction, NumberIssueAction}.php
├── Console/Commands/{SettingsShowCommand, FeaturesListCommand}.php
├── Data/SettingsFormData.php
├── Database/{Migrations, Factories, Seeders}/
├── Forms/SettingsForm.php
├── Http/{Controllers, Livewire, Middleware}/
├── Models/{Setting, NumberSequence}.php
├── Policies/SettingPolicy.php
├── Providers/PlatformModuleServiceProvider.php
├── Resources/{views, lang}/
├── Routes/web.php
├── Support/{DatabaseSettings, DatabaseNumberSeries, ConfigFeatures,
│            EditableSettings, SettingsCache, SeriesDefinition}.php
└── Tests/
```

---

## 1 · Settings

Los valores que el cliente cambia sin tocar el `.env` ni pedir un deploy: el
nombre de la organización, su RFC, su dirección, el correo de contacto.

### La cascada

```
fila en `settings`  →  config('kore-settings.defaults')  →  el $default de quien llama
```

Una instalación recién clonada **no tiene ninguna fila** y aun así responde a
todas las claves: manda `config/kore-settings.php`. La primera vez que alguien
guarda desde la pantalla se crea la fila, y a partir de ahí manda ella.

**Leer nunca escribe.** Es la diferencia con el
`NotariaConfiguracion::instancia()` del que viene el patrón: allí el primer
acceso insertaba la fila con los valores del config, así que una petición GET
podía acabar en un INSERT y dos peticiones simultáneas sobre una instalación
nueva, en dos.

### Consumirlo

```php
use App\Core\Contracts\Settings;
use App\Core\Data\OrganizationData;

final class InvoiceIssueAction extends Action
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(InvoiceData $data, int $issuedBy): Invoice
    {
        $organization = OrganizationData::fromSettings($this->settings->all());
        // ...
    }
}
```

`OrganizationData::fromSettings()` vive en `Core` y sólo recibe un array, así que
cualquier módulo compone el DTO sin importar nada de Platform (R5).

`$changedBy` es el id del actor y llega **por parámetro**, nunca de `auth()`: el
contrato vive en `Core`, donde ese helper está prohibido (R19), porque un comando
artisan o un seeder tienen que poder guardar un ajuste igual que una pantalla.

### Caché

Todo el mapa en **una** entrada (`kore.settings`, `kore-settings.cache_key`), no
una por clave: el layout pinta la organización entera en cada petición, y así
invalidar es olvidar una clave en vez de recorrer una lista que crece con cada
ajuste que añade un derivado. Cada escritura la invalida; el TTL
(`SETTINGS_CACHE_TTL`, una hora) sólo es la red por si alguien escribe en la
tabla desde fuera de la aplicación. `SETTINGS_CACHE_TTL=0` desactiva la caché.

Por encima hay una segunda memoria, la del propio objeto durante la petición: la
caché evita ir a la base, y ésa evita ir al driver de caché seis veces para
responder siempre lo mismo. Por eso el binding es `singleton`.

### Las claves llevan punto, y `config()` no las encuentra

`config('kore-settings.defaults.organization.name')` devuelve **`null`**. No es
un despiste: el punto es el separador de niveles de `Arr::get`, así que esa
llamada busca `defaults → organization → name` mientras que la clave del archivo
es literalmente `'organization.name'`, plana. Por eso `DatabaseSettings` lee el
array `defaults` **entero** y lo indexa a mano, y por eso un test que quiera
cambiar un defecto tiene que hacer `config()->set('kore-settings.defaults', [...])`
con el array completo.

Lo mismo pasa aguas abajo, y es la razón del `slug`: `wire:model="form.values.organization.name"`
haría `data_set` y crearía `values['organization']['name']`, y el validador
interpretaría lo mismo. El estado del formulario se guarda por slug
(`organization_name`) y `SettingsForm::toData()` deshace la traducción.
`EditableSettings::bySlug()` falla si dos claves producen el mismo slug.

### La pantalla

`GET /settings` → `settings.edit`, con `permission:settings.manage` en la ruta y
la Policy dentro del componente (R23: la llamada de Livewire viaja por
`/livewire/update`, donde el middleware de la ruta no corre).

**Los campos no están escritos en ninguna parte.** Salen de
`config('kore-settings.editable')`, así que añadir un ajuste son tres líneas de
configuración y ni una de PHP:

```php
'organization.website' => [
    'type' => 'string',            // string · text · email · bool · int
    'label' => 'Sitio web',        // español, que es el idioma fuente (R33)
    'required' => false,           // opcional
],
```

...más su entrada en `defaults`, que `PlatformConfigTest` exige: sin valor por
defecto, la pantalla ofrecería cambiar un ajuste que en una instalación recién
clonada no vale nada.

El `type` elige el control (`<x-kore::input>`, `<x-kore::textarea>`,
`<x-kore::toggle>`) y las reglas de validación. Una entrada puede traer su propio
`rules` y entonces manda ésa entera.

El botón **Restablecer** de cada campo borra su fila, con lo que la clave vuelve
a su defecto. No es «vaciar el campo»: una fila con `null` vale `null` de verdad
y tapa el defecto para siempre.

### El permiso

Uno solo, `settings.manage`, declarado como permiso especial en
`Module::specialPermissions()` y sembrado por `ModulesSeeder` (módulo `settings`)
y por `PlatformSeeder`. No hay CRUD de cuatro porque aquí no hay nada que crear
ni que borrar —una clave sin fila ya existe, vale su defecto— y porque ver los
ajustes **es** administrarlos: incluyen datos fiscales y de contacto.

`PlatformSeeder` es redundante en una instalación de fábrica y existe por el
derivado: lo primero que hace un proyecto hijo es recortar `ModulesSeeder` a sus
módulos, y con el recorte se va el permiso de una pantalla que sigue existiendo.
Lo llama `DatabaseSeeder` (y `E2eSeeder`), que no pertenece a ningún módulo, para
no cruzar la frontera de R5.

### El nombre de la organización en el layout

Lo inyecta un View Composer de `PlatformModuleServiceProvider` sobre
`components.layouts.*` como `$organization` (`OrganizationData`). Un composer y
no un `View::share` porque `share` se evalúa en cada petición aunque la respuesta
sea un JSON de la API o un 302 del login, y esto lee la base. Y **una** consulta,
no siete: sale del mapa cacheado.

### Comando

```bash
php artisan settings:show
```

| Clave | Valor efectivo | Origen |
|---|---|---|
| `organization.name` | Notaría 42 | base de datos |
| `organization.tax_id` | — | config |

La tercera columna es la razón de que el comando exista: «es Kore» no dice nada;
«es Kore **porque nadie lo ha cambiado**» y «es Kore **porque alguien lo guardó
así**» son dos situaciones distintas, y esa diferencia es la primera pregunta de
cualquier soporte que empiece por «¿por qué sale esto en el PDF?».

---

## 2 · Numbering

Folios correlativos por serie: `REC-2026-000123`.

### Por qué no es `max(id) + 1`

Porque dos peticiones simultáneas leen el mismo máximo y emiten el mismo folio.
Un folio repetido en un documento fiscal no es un bug de la aplicación: es un
problema con la autoridad tributaria.

`NumberIssueAction` —la **única** escritura de `number_sequences`— pone tres
candados, y los tres hacen falta:

1. **`DB::transaction`.** El contador y el documento avanzan juntos. Llamada
   desde dentro de la transacción que crea el documento (que es como se debe
   llamar), ésta se convierte en un savepoint y no cambia nada.
2. **`lockForUpdate()`.** El segundo que llega espera a que el primero termine en
   vez de leer el mismo `last_number`. Es literalmente lo que hace
   `ReciboService::emitir()` en Notarium, y la razón de que ese sistema lleve
   años sin un folio duplicado.
3. **Un reintento.** El bloqueo no sirve cuando la fila **todavía no existe**:
   dos peticiones sobre una serie estrenada no tienen qué bloquear, las dos ven
   que no hay contador y las dos intentan crearlo. Una gana, la otra choca con el
   índice único de `(series, scope, period)` y se reintenta **una** vez; en el
   reintento la fila ya está. Una sola vez, no en bucle: si el segundo intento
   también choca, lo que falla no es la carrera.

### Consumirlo

```php
use App\Core\Contracts\NumberSeries;

DB::transaction(function () use ($receipt, $series): void {
    $receipt->save();
    $receipt->issueNumber($series, 'receipt');       // trait HasIssuedNumber
});

$series->peek('receipt');                            // el siguiente, sin consumirlo
$series->next('receipt', 'CDMX');                    // contador aparte por sucursal
```

`next()` **consume**: el número sale ya gastado, exista o no la fila que lo va a
llevar. No hay reserva, a propósito — un contador que presta números necesita
saber cuándo se abandonó uno, y eso no se puede saber. Por eso se llama dentro de
la transacción del documento: si ésta se revierte, el folio se revierte con ella.

`peek()` no bloquea y no es una garantía: entre el `peek()` y el `next()` puede
emitir otro. Bloquear ahí detendría a todo el que emita mientras alguien tiene un
formulario abierto.

### Configurar una serie

`config/kore-numbering.php`:

```php
'series' => [
    'receipt' => ['prefix' => 'REC', 'reset' => 'yearly'],
],
```

Lo que no diga se hereda de `defaults`, y una serie **sin declarar** hereda
`defaults` entero: `next('lo-que-sea')` funciona sin configurar nada.

| Clave | Qué hace |
|---|---|
| `format` | `{PREFIX}` · `{YEAR}` · `{MONTH}` · `{SCOPE}` · `{NUMBER}` · `{NUMBER:6}` |
| `prefix` | lo que sustituye a `{PREFIX}` |
| `reset` | `never` (un contador para siempre) o `yearly` (uno por año natural) |
| `start` | primer número; lo sube un derivado que continúa la numeración de otro sistema |

`{NUMBER:6}` se **desborda con gracia**: el folio un millón sale con siete
dígitos en vez de truncarse, porque truncar sería emitir dos folios con el mismo
texto.

`scope` separa contadores dentro de la misma serie —una sucursal, una caja, un
tenant— sin declarar una serie nueva. `null` es el contador global y **no** es lo
mismo que un scope llamado `'null'`.

### El snapshot del emisor

El folio es la mitad del patrón. La otra mitad es que el documento **copia** en
su propia fila los datos de quien lo emite, en vez de referenciarlos:

```php
$receipt->issuer_snapshot = $settings->all();     // columna json
```

Si mañana la organización cambia de domicilio fiscal, el recibo del mes pasado
tiene que seguir diciendo el domicilio que el cliente recibió impreso. Un
`belongsTo` a la configuración reescribiría el pasado en silencio cada vez que
alguien toca `/settings`. Es lo que hace `notaria_snapshot` en la tabla `recibos`
de Notarium, y es la razón de que sea una columna JSON y no una relación.

### El trait

`App\Core\Concerns\HasIssuedNumber` es **opt-in**: hoy ningún modelo del
boilerplate lo usa. Necesita dos columnas (`number` única y nullable,
`number_issued_at`) y aporta `issueNumber()` y `hasIssuedNumber()`. Reemitir el
folio de un documento que ya lo tiene lanza `ConflictException`: dejaría el folio
anterior impreso en manos de alguien y un hueco en la serie, que es exactamente
lo que busca una auditoría. Si hay que anular, se cancela el documento y se emite
**otro**; la serie no retrocede.

### El límite de los tests

`NumberingTest` corre sobre SQLite en memoria, donde **no hay concurrencia
real**: no prueba que `lockForUpdate()` funcione —eso lo prueba el motor de la
base—. Prueba todo lo demás, que es lo que sí puede romper un cambio nuestro: 50
emisiones seguidas sin huecos ni duplicados sobre un solo contador, el reinicio
anual, los contadores por scope, el formato, el `start` y el `peek`. La carrera
de dos procesos creando la fila a la vez se prueba por el único camino honesto
que hay en una base de un solo escritor: creando la fila por debajo y
comprobando que la emisión la respeta.

---

## 3 · Features por instalación

`config/features.php` responde una pregunta que ninguna de las otras capas
responde: **qué módulos incluye la licencia de esta instalación**.

| Capa | La frase que dice | Quién la cambia |
|---|---|---|
| `config/kore-app.php` | «este boilerplate **trae** esta capacidad» | quien despliega, en el `.env` |
| `config/features.php` | «tu **licencia** no incluye esto» | quien vende o instala, en el `.env` |
| Laravel Pennant | «**todavía** no te toca» | el producto, por usuario o por porcentaje |
| spatie/laravel-permission | «no **tienes permiso**» | el administrador del cliente |

Las cuatro dan un «no» y las cuatro son un «no» distinto. Mezclarlas duele en el
sitio de siempre: en Notarium, apagar un módulo no licenciado se hacía
quitándole el permiso a todos los roles, y entonces nadie sabía si el cliente no
veía Escrituras porque no lo había comprado o porque alguien le había tocado los
permisos. Son dos preguntas y necesitan dos respuestas. Ver
[`../architecture/toggles.md`](../architecture/toggles.md) §«Tres capas».

Es un archivo de configuración y no una tabla a propósito: lo que responde es qué
**compró** el cliente, y eso no lo cambia nadie desde dentro de la aplicación.
Con los flags en la base, cualquiera con acceso a la pantalla de ajustes se
licencia a sí mismo el módulo que quiera.

### Las tres formas de consumirlo

```php
// 1. El middleware, en una ruta de un módulo opcional
Route::get('/reports', ...)->middleware(['auth', 'feature:reports', 'permission:reports.view']);

// 2. La directiva, para esconder el enlace
@feature('reports')
    <x-kore::sidebar.item ... />
@endfeature

// 3. El contrato, en código
resolve(App\Core\Contracts\InstallationFeatures::class)->enabled('reports');
```

`feature:` **no sustituye** al permiso: dice si el módulo está en esta
instalación, no si este usuario puede usarlo. Una ruta de un módulo opcional
lleva los dos. Y la directiva no sustituye al middleware, igual que `@can` no
sustituye a la Policy: una vista que no pinta el enlace no protege la ruta.

El middleware devuelve **403 y no 404** aunque un 404 escondiera mejor qué
módulos existen: el cliente tiene que poder distinguir «esto no lo tienes» —que
se resuelve llamando a comercial— de «esta dirección no existe», que es un enlace
roto.

Una clave que no existe vale `false`: lo que no está licenciado explícitamente,
no está licenciado.

Ninguna ruta del boilerplate lleva `feature:` puesto. `/settings` tampoco:
los ajustes son del núcleo, y ponerle una licencia delante sería poder vender un
producto que no se puede configurar.

```bash
php artisan features:list      # feature · incluido · variable de entorno
php artisan about              # Features · 2 de 3 (features:list)
```

---

## Tablas

| Tabla | Qué guarda |
|---|---|
| `settings` | `key` (única) · `value` (json) · `changed_by` (FK nullable a `users`) |
| `number_sequences` | `series` · `scope` · `period` · `last_number`, con única `(series, scope, period)` |

`value` es JSON porque un ajuste no siempre es texto: un `false` guardado como
`varchar` vuelve como cadena vacía. `changed_by` va con `nullOnDelete`: borrar al
administrador que configuró la organización no puede borrar el nombre de la
organización.

`last_number` es el último número **entregado**, no el siguiente: el folio 7
emitido deja la fila en 7, y una fila recién creada vale `start - 1`, con lo que
la primera emisión devuelve `start` sin ningún caso especial.

**En MySQL y en SQLite dos filas con NULL no chocan en un índice único**, así que
la clave única no protege por sí sola al contador global (scope y periodo nulos):
a ése lo protegen el `lockForUpdate()` y el reintento. El índice es la red del
caso con scope o con periodo, que es el que más filas produce.

## Tests

```bash
./vendor/bin/pest app/Modules/Platform
```

| Archivo | Qué cubre |
|---|---|
| `SettingsTest` | la cascada, que leer no escriba, tipos, y que la caché se invalide |
| `SettingsScreenTest` | ruta, permiso, componente, validación, `restore` y el layout |
| `SettingsShowCommandTest` | las tres columnas de `settings:show` |
| `NumberingTest` | 50 emisiones, reinicio anual, scope, formato, `peek`, la carrera |
| `HasIssuedNumberTest` | el trait sobre una tabla que el propio test crea |
| `FeaturesTest` | middleware 403/200, la directiva y `features:list` |
| `PlatformConfigTest` | la forma de los tres archivos de configuración y los bindings |

E2E: `tests/e2e/specs/platform/settings.spec.ts` con
`tests/e2e/pages/SettingsPage.ts`, más la fila de `/settings` en
`tests/e2e/fixtures/access-map.ts` (R52), que genera solos el smoke y el test de
autorización por rol.
