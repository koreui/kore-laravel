# Módulo Files — archivos con versiones

**TL;DR**: `FILES_ENABLED=true` enciende un almacenamiento de archivos con
**versionado por slot** sobre spatie/laravel-medialibrary. Quien lo usa no
conoce el paquete: habla con `App\Core\Contracts\FileStore` y recibe DTOs. Un
archivo nunca se destruye al reemplazarlo —se archiva—, se sirve por una URL
firmada y temporal, y opcionalmente se comprime y se sube a S3/R2 en cola.

Con el toggle apagado no existe nada de eso; la tabla `media` se migra igual.

## Por qué existe

Todo proyecto acaba subiendo ficheros, y todos tropiezan con las mismas cuatro
piedras:

1. **«¿Cuál era el documento de antes?»** Reemplazar un fichero suele borrar el
   anterior, y eso está bien para un avatar y es un problema serio para una
   escritura, un justificante o un contrato firmado. El módulo separa
   **archivar** (reversible, lo que hace la interfaz) de **borrar** (destructivo,
   lo que hace la purga programada).
2. **«¿Por qué el navegador sigue enseñando la foto vieja?»** Cuando el fichero
   se sobrescribe en su sitio, la URL no cambia y el caché gana. La respuesta es
   un `v` dentro de la firma, y está explicada abajo con su cicatriz.
3. **«¿Por qué la subida tarda ocho segundos?»** Porque escribe directamente en
   S3 y la petición espera a la red. El módulo escribe en disco local y sube en
   cola.
4. **«¿Quién puede ver este fichero?»** Un disco público es una decisión que
   nadie recuerda haber tomado. Aquí el disco por defecto es privado y la única
   forma de enlazar un archivo es una URL firmada que caduca.

Sale de la cantera de Notarium (`MediaStorageService`, `ComprimirPdfJob`,
`OptimizarImagenJob`, `SyncMediaToR2Job`, `MediaLocalController`) y de
asper-server (`MediaUrl`, de donde viene el `v` dentro de la firma). Lo que
cambia respecto de los dos: allí eran servicios y jobs sueltos atados al dominio;
aquí son un contrato en `Core`, Actions, y un módulo que se puede apagar.

## Toggle

| Variable | Default | Qué enciende |
|----------|---------|--------------|
| `FILES_ENABLED` | `false` | Binding de `FileStore`, ruta `files.serve`, listeners de la tubería, comando `files:cleanup` y su tarea del scheduler |

Es una de las catorce claves de `config/kore-app.php` y la leen
`FilesModuleServiceProvider`, `routes/console.php` (para el scheduler),
`AppServiceProvider::configureAbout()` y las dos pantallas de Users que consumen
el módulo.

**Qué NO apaga el toggle, y son dos cosas.**

La primera es **el esquema**: la migración de `media` se carga siempre. Un toggle
apaga rutas y comportamiento, nunca la forma de la base; si fuera condicional,
dos instalaciones del mismo commit tendrían esquemas distintos según el `.env`
del día en que se migró. Es el mismo criterio que `devices` y que las passkeys.

La segunda es **el espacio de vistas**. Blade compila las etiquetas de
componente al compilar la plantilla, no al ejecutarla: un
`@if (config('kore-app.files.enabled'))` alrededor de `<x-files::slot-upload>` no
evita nada, porque para cuando el `if` se evalúa el componente ya tuvo que
existir. Con el `loadViewsFrom` dentro del toggle, la pantalla de edición de
usuarios devolvía un 500 en toda instalación con el módulo apagado. Registrar el
espacio de vistas siempre no expone nada —es el caso que la excepción de R10
contempla— y lo comprueba `FilesToggleTest`.

Con el toggle apagado, resolver `FileStore` **lanza** un
`BindingResolutionException`. Es a propósito: «esta instalación no guarda
archivos» es una respuesta, y un `null` a mitad de una subida no. Por eso quien
lo consume pregunta antes por el toggle.

## Configuración

`config/files.php` guarda los parámetros; **no** duplica el toggle (mismo reparto
que `kore-api.php` respecto de `API_ENABLED`).

| Clave | Env | Default | Qué es |
|-------|-----|---------|--------|
| `disk` | `FILES_DISK` | `local` | Disco de los archivos privados (`storage/app/private`) |
| `public_disk` | `FILES_PUBLIC_DISK` | `public` | Disco de los slots públicos |
| `staging_disk` | `FILES_STAGING_DISK` | `local` | Disco de paso mientras espera al sync |
| `url_ttl_minutes` | — | `30` | Cuánto vive una URL firmada |
| `throttle` | — | `60,1` | Límite por IP de `files.serve` |
| `max_upload_kb` | — | `51200` | Tamaño máximo que valida la aplicación (50 MB) |
| `compression.enabled` | `FILES_COMPRESSION` | `false` | Comprimir en cola tras guardar |
| `compression.image_quality` | — | `85` | Calidad de recompresión de imágenes |
| `compression.ghostscript_binary` | — | `gs` | Binario para los PDF |
| `compression.tmp_dir` | — | `null` | Temporales de la compresión |
| `sync.enabled` | `FILES_SYNC` | `false` | Mover de staging al disco de destino en cola |

`config/media-library.php` está **publicado** y tocado en tres sitios —`disk_name`
lee `FILES_DISK`, `path_generator` es el del módulo y `max_file_size` sube a 50 MB
para acompañar a `max_upload_kb`—; el resto es el archivo del paquete tal cual,
para que un `diff -w` contra `vendor/` al actualizar enseñe sólo lo que cambia el
paquete.

> ⚠️ **R12**: `media-library.disk_name` lee `env('FILES_DISK')` y **no**
> `config('files.disk')`, porque los `config/*.php` se cargan en orden
> alfabético y `files` todavía no existe cuando `media-library` se evalúa. La
> cifra vive en dos sitios; que no se separen lo vigila `FilesConfigTest`.

## El contrato: `App\Core\Contracts\FileStore`

```php
public function store(HasMedia $owner, UploadedFile $file, FileSlotData $slot, int $uploadedBy): StoredFileData;
public function current(HasMedia $owner, FileSlotData $slot): ?StoredFileData;
public function history(HasMedia $owner, FileSlotData $slot): Collection;   // desc por versión
public function archive(int $fileId): void;                                  // idempotente
public function url(int $fileId, ?int $minutes = null): string;              // firmada y temporal
public function delete(int $fileId): void;                                   // destructivo
```

Vive en `Core` y lo implementa `App\Modules\Files\Support\MediaFileStore`, que se
bindea como singleton en el `register()` del provider. Un módulo cliente **nunca**
importa nada de `App\Modules\Files` salvo sus `Events` (R5): tipa el contrato y
recibe `App\Core\Data\StoredFileData`.

`$uploadedBy` llega por parámetro y no de `auth()`: el contrato está en `Core`,
donde esos helpers están prohibidos (R19), y una Action o un comando tienen que
poder guardar un archivo igual.

### Slots y versiones

Un **slot** es un hueco con nombre dentro de un modelo, y es lo que convierte
«subir un fichero» en «poner la versión 3 de la escritura del expediente 42».

```php
new FileSlotData(collection: 'avatar');                                  // un hueco
new FileSlotData(collection: 'documentos', key: ['tipo' => 'escritura']); // otro
new FileSlotData(collection: 'branding', public: true);                  // al disco público
```

- `collection` es la colección de media-library.
- `key` distingue dos slots **dentro** de la misma colección. Puede ir vacía.
- `public` decide el disco. Por defecto, privado.

`FileSlotData::fingerprint()` es lo que permite buscar un slot con un `where` en
vez de traerse la colección entera y filtrar en PHP, que es lo que hacía
Notarium. Ordena la `key` (`ksort`) antes de serializar —así `['a'=>1,'b'=>2]` y
`['b'=>2,'a'=>1]` son el mismo slot— y hashea con `xxh128`, que es un
identificador y no un secreto: la `key` se guarda al lado en claro para poder
leer la fila a ojo.

**Lo que NO puede entrar en `key`**: la etiqueta que elige quien sube el archivo.
Es la cicatriz de Notarium. Si el nombre visible contara como identidad,
reemplazar un documento cambiándole el nombre abriría un slot nuevo en vez de
crear la versión 2 del que ya había, y el historial se partiría en dos sin que
nadie lo notara.

Al guardar, la Action calcula la versión siguiente, escribe el fichero y **sólo
entonces** archiva las anteriores. Al revés, un fallo al escribir dejaría el slot
vacío: el archivo que había ya no sería el vigente y el nuevo no existiría.

### Archivar ≠ borrar

| | Qué hace | Quién la llama |
|---|---|---|
| `archive()` | `is_current = false` + `replaced_at`. El fichero se queda. | La interfaz: `deleteUpload()` del trait, la papelera de `<x-kore::upload deletable>` |
| `delete()` | Borra la fila y el fichero. Sin vuelta atrás. | El dueño que se borra a sí mismo y `files:cleanup` |

Ningún botón del boilerplate llama a `delete()`, y desde la v2.3.0 no es una
convención sino **R56**: disallowed-calls prohíbe `FileStore::delete()` en todas
partes menos en el propio módulo, en los `Console/` y en los `Listeners/` de
cualquier módulo, y un arch test barre además los componentes Livewire que
consumen el contrato. Ver [`../architecture/rules.md`](../architecture/rules.md).

## La URL firmada y el `v=`

`FileStore::url()` devuelve siempre una ruta de la aplicación, firmada y con
caducidad, con la marca de tiempo del fichero **dentro de lo firmado**:

```
/files/42?v=1788528000&expires=1788529800&signature=…
```

**Por qué el `v`.** Cuando un fichero se sobrescribe en su sitio —rotar una
imagen, sustituir un PDF, comprimirlo— la ruta no cambia, así que el navegador
(y `expo-image`, y cualquier CDN por delante) seguiría enseñando la copia
cacheada para siempre. El timestamp la cambia.

**Por qué DENTRO y no pegado detrás con un `&v=`.** La firma cubre la query
entera: un parámetro añadido a mano invalida la URL. Es la lección literal de
`MediaUrl` en asper-server, y `MediaFileStoreTest` la fija con un test que
manipula el `v` y espera un 403.

**Por qué la ruta no lleva `auth`.** La firma **es** la autorización: emitir la
URL es afirmar que quien la pidió ya pasó por la policy del dueño del archivo, y
por eso se construye desde las superficies que ya autorizaron —el componente, el
resource de la API— y nunca desde una vista suelta. Eso es **R55**, y lo vigila
`kore:arch:check`: fuera de este módulo nadie escribe un `Storage::url()`, un
`temporaryUrl()` ni un `getUrl()` de media-library. Poner `auth` no sería más
seguro y sí rompería los tres casos para los que existe una URL firmada: el
`<img src>` de un correo, el PDF que un convertidor externo descarga y el enlace
que se le da a alguien sin cuenta. Lo que la protege es que caduca, que no se
puede modificar y que está limitada por IP (`files.throttle`).

`FileServeController` decide qué responder por el **driver del disco**: si es
local, sirve el fichero por stream; si no, redirige a la URL temporal del bucket.
Proxyear 40 MB a través de PHP para volver a mandarlos al navegador es pagar dos
veces el ancho de banda y ocupar un worker todo el rato.

R52 no exige esta ruta en `tests/e2e/fixtures/access-map.ts` porque el mapa es de
rutas literales sin parámetros y ésta lleva `{file}`. La cubren `FileServeTest`
(403 sin firma, 200 con ella, URL distinta al cambiar el fichero, 404 si el
fichero no está) y `tests/e2e/specs/users/avatar.spec.ts`.

## Compresión y sincronización (opcionales)

Las dos son trabajo en cola disparado por el evento `FileStored`, que es la
frontera pública del módulo (R5): cualquier otro módulo puede escucharlo para
pasar un antivirus, extraer texto o avisar a quien revisa.

> **R3 · por qué listeners y no jobs.** La lista de carpetas de un módulo es
> cerrada y no incluye `Jobs/`. El trabajo asíncrono del boilerplate se modela
> como un listener `ShouldQueue` que reacciona a un evento del módulo y delega en
> una Action. Se pierde el `dispatch()` a mano y se gana que todo trabajo
> asíncrono tenga un disparador con nombre en `Events/`.

**Compresión** (`FILES_COMPRESSION=true`). PDF con Ghostscript
(`-dPDFSETTINGS=/ebook`), imágenes con `Spatie\Image\Image`. Dos reglas:

- el resultado **sólo sustituye al original si pesa menos** —recomprimir un JPEG
  ya optimizado suele engordarlo—;
- **fallar no cuesta el archivo**: cualquier problema acaba en `failed` o
  `skipped` con el fichero original intacto y servible. `skipped` es «no había
  nada que hacer» (un `.zip`, o Ghostscript ausente); `failed` es «había que
  hacerlo y no se pudo». Sólo el segundo merece que alguien mire.

Requisitos fuera de PHP: Ghostscript para los PDF (`brew install ghostscript`,
`apt-get install ghostscript`) y un driver de imagen (`gd` o `imagick`). Por eso
viene apagado: un boilerplate no puede dar por hechos ninguno de los dos.

**Sincronización** (`FILES_SYNC=true`). Con esto encendido el fichero se escribe
primero en `staging_disk` y un listener lo sube a `disk`. El orden importa:
subir por stream → verificar que existe y que el tamaño coincide → apuntar la
fila al disco nuevo → borrar la copia local. Cualquier otro orden tiene una
ventana en la que el fichero no está en ningún sitio alcanzable. Si el tamaño no
cuadra se borra la copia remota y se relanza para que la cola reintente; agotar
los intentos deja la fila en `failed` con el fichero local intacto **y
sirviéndose**: un fallo de sincronización degrada el coste, nunca el acceso.

**Los dos listeners nunca corren sobre el mismo archivo.** Comprimir cambia el
fichero, así que subirlo antes sería subirlo dos veces: con la compresión
encendida es ella quien encadena la sincronización al terminar (y también si
falla), y `SyncStoredFile` sólo se registra cuando la compresión está apagada.
Ese reparto se decide en el provider, en un sitio.

## `HandlesSlotUploads`

El trait de `Core` que pone la subida en una pantalla Livewire. Aporta
`$slotUpload`, `uploadSlot()`, `deleteUpload()` y `archiveSlot()`; el componente
pone las tres respuestas que cambian de una pantalla a otra:

```php
final class FormComponent extends Component
{
    use HandlesSlotUploads;

    /** @return array{owner: HasMedia, slot: FileSlotData, rules: array<int, mixed>} */
    protected function slotUploadTarget(): array
    {
        return [
            'owner' => $this->avatarOwner(),
            'slot' => new FileSlotData(collection: 'avatar'),
            'rules' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    protected function slotUploadActorId(): int
    {
        return (int) auth()->id();     // el componente SÍ puede; el trait no (R19)
    }

    protected function authorizeSlotUpload(): void
    {
        $this->authorize('update', $this->avatarOwner());
    }
}
```

Tres cosas que no se ven a simple vista:

- **R19** · el actor entra por `slotUploadActorId()` porque el trait vive en
  `Core`, donde `auth()` está prohibido. No es purismo: el mismo código tiene
  que servir desde un job o un comando, donde ese helper devuelve `null` en
  silencio y el archivo acabaría sin dueño.
- **R23** · `uploadSlot()` llama a `authorizeSlotUpload()` **siempre** y antes de
  tocar nada, y el método es `abstract`: un componente que se olvide de autorizar
  no llega a compilar. Es más fuerte que el check textual de R23, que barre los
  `public function` cuyo nombre empieza por un verbo de escritura dentro de
  `app/Modules/{X}/Http/Livewire` y no alcanza a un trait de `Core`.
- **`deleteUpload()` archiva.** Es el nombre que `<x-kore::upload deletable>`
  invoca por defecto, así que es lo que ocurre al pulsar la papelera. Y lo que
  tiene que ocurrir ahí es archivar.

Después de subir o archivar, el trait suelta `$slotUpload`, hace
`unsetRelation('media')` en el dueño —sin eso la pantalla seguiría pintando el
archivo anterior durante el resto de la petición, que es el despiste más caro de
media-library— y despacha `slot-uploaded`.

## El componente `<x-files::slot-upload>`

```blade
<x-files::slot-upload
    :current="$this->avatar"
    :label="__('Foto de perfil')"
    :hint="__('PNG, JPG o WebP. Máximo 2 MB.')"
    accept="image/png,image/jpeg,image/webp"
    :max-size="2"
    :action="__('Guardar foto')"
/>
```

`current` es un **array** (o `null`), nunca un modelo (R30): lo prepara el
componente Livewire a partir de `StoredFileData`, con la URL firmada ya resuelta.
Las cuatro claves son las que `<x-kore::upload static>` entiende: `name`, `size`,
`type`, `url`.

Por dentro son **dos** `<x-kore::upload>`, porque en koreUi `static` y la zona de
subida se excluyen: el archivo vigente va en el estático (con papelera) y la zona
para subir el siguiente va en el otro. Los dos llevan `id` explícito
(`slot-upload-current`, `slot-upload-input`) para que sus `<label for>` no
apunten al mismo sitio.

El botón es `type="button"` a propósito: el componente vive dentro del
`<form wire:submit>` de la pantalla y un submit se llevaría el formulario entero.
Y guarda con un clic y no en el hook `updated` porque elegir un fichero y
guardarlo son dos decisiones: si no, la versión nueva quedaría creada aunque
quien la eligió se arrepienta y cierre la pantalla.

## El avatar como ejemplo

`App\Models\User` implementa `HasMedia` con `InteractsWithMedia` **siempre**,
también con el toggle apagado: es la forma del modelo, no una capacidad. La
colección `avatar` se declara **sin `singleFile()`** a propósito —esa opción
borra el archivo anterior al añadir uno nuevo, y aquí quien decide qué pasa con
la versión anterior es el store, que la archiva—.

En `/users/{id}/edit`, `FormComponent` usa `HandlesSlotUploads` y la vista pinta
el componente sólo si hay toggle **y** hay usuario: en el alta todavía no existe
la fila de la que colgar el archivo.

En `/users`, `TableUsers` añade una `ImageColumn` con la URL firmada ya resuelta
desde el componente (R30). **No hay N+1**: la tabla hace `->with('media')` una
vez, `MediaSlots::versions()` usa la relación cargada en vez de consultar, y
`MediaFileStore` recuerda la marca de tiempo del fichero que acaba de leer para
que `url()` tampoco vuelva a la base. Lo fija un test que cuenta las consultas.

## `files:cleanup`

```bash
php artisan files:cleanup --days=30            # purga de verdad
php artisan files:cleanup --days=30 --dry-run  # el ensayo, sin escribir
```

Borra las versiones archivadas hace más de N días, con sus ficheros. Tres
condiciones y las tres a la vez: `is_current = false`, `replaced_at` presente y
anterior al corte. La versión vigente no se toca **nunca**, tenga la edad que
tenga; y una fila archivada sin marca de tiempo se conserva, porque es un dato
incompleto.

El plazo va en `--days` y no en el config a propósito: purgar es destructivo y la
cifra tiene que verse en la línea que la aplica. El scheduler la corre a las
04:30, detrás del backup de las 02:00: si la purga se lleva algo que hacía falta,
el zip de la noche todavía lo tiene.

## Gotchas

- **La relación `media` se cachea.** Después de subir o archivar hay que hacer
  `unsetRelation('media')` en el dueño o la pantalla seguirá pintando el archivo
  anterior. El trait lo hace por ti; si guardas desde una Action propia, hazlo tú.
- **`is_current` se filtra en PHP, no en SQL.** Los booleanos dentro de un JSON
  no viajan igual en SQLite, MySQL y Postgres. Lo que sí va a la base es la
  huella del slot, que es una cadena de longitud fija.
- **Si el disco remoto falla, la fila y el fichero no se separan.** La fila sólo
  se apunta al disco nuevo después de verificar tamaño en destino, y la copia
  local sólo se borra después de eso. Una fila en `failed` significa «el fichero
  está aquí y no allí», nunca «el fichero no está».
- **`media-library.max_file_size` y `files.max_upload_kb` son la misma cifra en
  dos archivos.** Si el paquete cortara antes que la validación, un archivo
  admitido por el formulario reventaría al guardarse y el mensaje hablaría de
  bytes.
- **Los ficheros de prueba se generan, no se copian.** `tests/e2e/fixtures/files/avatar.png`
  es un PNG de 1×1 de 69 bytes creado con un script: no arrastra licencia ni
  metadatos.

## Tests

| Archivo | Qué fija |
|---------|----------|
| `Tests/Feature/FilesToggleTest.php` | R10: qué se registra y qué no, más las dos excepciones (esquema y vistas) |
| `Tests/Feature/FilesConfigTest.php` | Las cifras que viven en dos archivos por culpa de R12 |
| `Tests/Feature/FileStoreActionTest.php` | El versionado por slot, los discos y el evento |
| `Tests/Feature/FileArchiveAndDeleteActionsTest.php` | Archivar ≠ borrar, e idempotencia |
| `Tests/Feature/FileCompressActionTest.php` | Que fallar comprimiendo nunca cuesta el archivo |
| `Tests/Feature/FileSyncActionTest.php` | El orden de la subida y las ramas de fallo |
| `Tests/Feature/MediaFileStoreTest.php` | El contrato visto desde fuera, sin tocar `Media` |
| `Tests/Feature/FileServeTest.php` | La firma como autorización y el `v` dentro de ella |
| `Tests/Feature/FileListenersTest.php` | El encadenado compresión → sync |
| `Tests/Feature/FilesCleanupCommandTest.php` | Las tres condiciones de la purga |
| `Tests/Unit/SlotPathGeneratorTest.php` | La forma de la ruta en disco |
| `Tests/Unit/FileSlotDataTest.php` | La huella del slot y `isImage()` |
| `app/Modules/Users/Tests/Feature/UserAvatarTest.php` | El consumidor de referencia, con su autorización |
| `tests/e2e/specs/users/avatar.spec.ts` | El flujo entero en el navegador |
