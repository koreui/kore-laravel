# Reglas de arquitectura (R1–R62)

**TL;DR**: las reglas del boilerplate son numeradas, citables (`R5`) y cada una
dice quién la verifica y con qué comando. Lo que se puede verificar, falla el
build. Lo que no, se revisa a mano y lo dice. Las excepciones tienen gramática
fija y fecha de caducidad, y **el agente nunca se aprueba una a sí mismo**.

Este archivo es la fuente de verdad. `CLAUDE.md` y `AGENTS.md` sólo resumen y
enlazan aquí; los docs de `docs/` explican el cómo.

## Cómo se lee una regla

```
### R{n} · Enunciado de ejemplo
El enunciado, en una o dos frases.
> Enforcement: herramienta · comando · severidad
> Escape: cuál de las dos válvulas aplica (o «ninguna»)
**Por qué.** La razón, no el dogma.
**Cicatriz.** El incidente real que la originó, con su versión.
```

Algunas reglas llevan además una línea **Relacionada: R{n}**, que apunta a la
regla vecina con la que comparten frontera. No se fusionan a propósito: los
números ya están citados desde el código y desde los skills, y renumerar
rompería esas citas.

Severidades:

| Severidad | Qué significa |
|-----------|---------------|
| **Error** | Falla el build. Nadie mergea con esto en rojo. |
| **Warning** | Se reporta y se lee, no bloquea. |
| **Manual** | No hay verificador; se revisa en code review. |

«Sin cicatriz todavía» significa exactamente eso: la regla es preventiva y
todavía no ha costado un incidente. El día que lo cueste, se anota aquí.

---

## §1 · Arquitectura

### R1 · 1 Action = 1 caso de uso con `handle()`
Una Action es `final`, extiende `App\Core\Actions\Action` y expone **un único**
método público llamado `handle(...)`.
> Enforcement: Pest arch · `./vendor/bin/pest tests/Arch` · **Error** · + PHPat (`haveOnlyOnePublicMethodNamed`) · `composer analyse` · **Error**
> Escape: `arch-accepted` (ver §Válvulas)

**Por qué.** El día que una Action tiene dos métodos públicos deja de ser un
caso de uso y pasa a ser el `Service` de 900 líneas que el patrón vino a
evitar. Un método público = un nombre = una unidad testeable y reutilizable
desde un job, un comando o un seeder.

**Cicatriz.** Hasta la v1.1.0 el Action Pattern era prosa: la auditoría de
septiembre de 2026 contó **0 clases** extendiendo `Core\Actions\Action`
mientras `CLAUDE.md` lo anunciaba como regla de oro número uno.

### R2 · Naming `{Domain}{Object}{Verb}Action`
`AuthUserRegisterAction`, `OrderCancelAction`. Cuando el objeto coincide con el
dominio se omite el prefijo repetido: `UserCreateAction` dentro de `Users`.
> Enforcement: Pest arch (sufijo `Action`) · `./vendor/bin/pest tests/Arch` · **Error** · el resto del nombre: **Manual**
> Escape: ninguna

**Por qué.** El nombre de la clase es el índice del dominio. Con la convención,
`ls app/Modules/Orders/Actions` es la lista de lo que el módulo sabe hacer.

**Cicatriz.** Sin cicatriz todavía.

### R3 · Lista cerrada de carpetas por módulo
Un módulo sólo puede tener: `Actions`, `Console`, `Data`, `Database`
(`Migrations`, `Factories`, `Seeders`), `Enums`, `Events`, `Exports`, `Forms`,
`Http` (`Controllers`, `Livewire`, `Requests`, `Middleware`, `Resources`),
`Listeners`, `Models`, `Policies`, `Providers`, `Resources` (`views`, `lang`),
`Routes`, `Rules`, `Support`, `Tests`, y `Fortify` como única carpeta de
adaptadores de paquete. Cualquier otra rompe el build.
> Enforcement: Pest arch · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: ninguna — ampliar la lista es una decisión de arquitectura: se
> actualiza este doc, `module-pattern.md` y el test, en el mismo commit.

**Por qué.** Una carpeta nueva es una capa nueva. Si cada módulo inventa la
suya (`Services/`, `Repositories/`, `Helpers/`), el «modular monolith» pasa a
ser cuatro arquitecturas conviviendo, y la IA copia la que encuentre primero.

**Las tres que entraron en la v2.1.0, y con qué forma.** No basta con abrir la
lista: una carpeta permitida sin nada que la defina es el `Services/` de otra
vez, con otro nombre. Dos de las tres traen su propio arch test:

| Carpeta | Qué puede vivir dentro | Vigilado por |
|---------|------------------------|--------------|
| `Enums/` | enums **backed** (`string` o `int`) del dominio | Pest arch (`R3 · los Enums de módulo son enums backed`) |
| `Http/Resources/` | API Resources que extienden `Illuminate\Http\Resources\Json\JsonResource` | Pest arch (`R3 · los Http/Resources de módulo extienden JsonResource`) |
| `Exports/` | la salida hacia fuera: Excel, CSV, PDF | sólo R3 — lo que va dentro lo fija el paquete de exportación que instale el proyecto |

**`Services/` sigue fuera, y a propósito.** Es *la* carpeta que hay que
justificar, porque es la que absorbe todo lo que a alguien le da pereza
nombrar. El caso legítimo existe —asper-server tiene un motor de formularios en
`Formats/Services/FormEngine/`: un intérprete de esquemas con estado propio,
que no es un caso de uso ni un DTO ni un helper—, y el caso que la regla evita
también: `ExpedienteService` de Notarium, **1.147 líneas** en una clase, que es
exactamente lo que R1 describe cuando dice «el `Service` de 900 líneas que el
patrón vino a evitar». Si de verdad hace falta, se pide como cualquier otra
capa nueva (ver `module-pattern.md`), con el equipo delante.

**Cicatriz.** Doble. Los stubs que publica Fortify vivían en
`App\Modules\Auth\Actions\Fortify\` y obligaban a una excepción permanente en
el arch test de Actions (sus nombres y firmas los fija el paquete, no el
boilerplate). En la v1.1.0 se mudaron a `App\Modules\Auth\Fortify\`.

La segunda es la que amplió la lista. **asper-server**, hijo del boilerplate y
con esta regla escrita delante, creó `Enums/` en **9 de sus 15 módulos**,
`Http/Resources/` en **5** y `Exports/` en **2**. No fue un despiste de un
módulo: fue el proyecto entero diciendo que la lista se había quedado corta.
Una regla que el 60 % del código de un derivado incumple no está protegiendo
nada —está enseñando a ignorarla—, así que en la v2.1.0 se amplió la lista y se
le puso forma a lo que entra. Lo que **no** se amplió es `Services/`: ahí asper
tiene dos módulos y uno de los dos es el caso legítimo, no la regla.

### R4 · Sin lógica de negocio en controllers, Livewire ni Forms
El Form valida y empaqueta (`rules()` + `toData()`); el componente hace
`autorizar → validar → DTO → Action`; la escritura vive en la Action. Regla
práctica: si un método de la capa de entrega pasa de ~10 líneas, sobra.
> Enforcement: PHPat (el dominio no depende de la capa de entrega) · `composer analyse` · **Error** · el tamaño: **Manual**
> Escape: `arch-accepted`

**Por qué.** Lo que vive en un componente Livewire sólo se puede ejecutar desde
un navegador. Lo que vive en una Action se ejecuta también desde un job, un
comando de importación o un test unitario de tres líneas.

**Cicatriz.** `UserForm::store()` persistía el usuario, sincronizaba roles y
permisos y decidía si hashear la contraseña. Desapareció en la v1.1.0 a favor
de `toData()` + `UserCreateAction` / `UserUpdateAction`.

### R5 · Sin imports cruzados entre módulos, salvo los eventos
`App\Modules\X` no usa `App\Modules\Y`. La comunicación va por
`App\Core\Contracts`, DTOs y enums de `Core`, o **eventos**.

`App\Modules\{Domain}\Events\` es la **frontera pública** de un módulo y sí se
puede importar desde otro: un `Listeners\NotifyLoQueSea` tipa el evento que
escucha, y el provider que los cablea con `Event::listen()` también. Todo lo
demás del módulo vecino —`Models`, `Actions`, `Support`, `Data`, `Policies`,
`Http`— sigue prohibido, en los dos sentidos. Los `Tests/` de cada módulo
también pueden cruzar: montan el mundo real.
> Enforcement: PHPat (todos los pares, generados desde `app/Modules/*`, excluyendo `Tests` y `Events` del destino) · `composer analyse` · **Error** · + Pest arch (Users ↔ Auth, y la forma de la regla generada en `PhpatArchitectureTest`) · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: `arch-exception` (ver §Válvulas)

Relacionada: R6 (la arista `Core → Modules`, que ninguna válvula abre).

**Por qué.** Un import cruzado es una dependencia que nadie declaró y que
aparece el día que quieres extraer el módulo, apagarlo con un toggle o
reutilizarlo en otro proyecto.

**Por qué los eventos no cuentan.** La dirección es la que importa. Un evento es
un contrato **hacia afuera**: `final readonly`, sin comportamiento, publicado
por el módulo origen sin saber quién escucha. Quien depende es el que reacciona,
y esa dependencia es exactamente la que la arquitectura quiere que exista —es lo
que `module-pattern.md` lleva recomendando desde la v1.0.0—. Prohibirla no la
elimina: la disfraza. Sin poder importar el evento, la única forma de cablear un
listener es `Event::listen('App\Modules\Payments\Events\Cobrado', ...)` con el
nombre en un string, que es la misma dependencia pero invisible para PHPat, para
el IDE y para el refactor que renombre la clase.

**Cicatriz.** Doble. Hasta la v1.1.0 `Users` importaba `Auth\Models\{Role,
Module}` en **cuatro** archivos de producción —`Forms/UserForm.php`,
`Http/Livewire/FormComponent.php` (dos imports: `Role` y `Module`),
`Http/Livewire/TableUsers.php` y `Policies/UserPolicy.php`, cinco imports en
total— más otros **tres** de `Tests/`, que la regla permite. El arch test
correspondiente estaba comentado con un `TODO v1.1` al lado. Se resolvió con
`App\Core\Contracts\AuthorizationCatalog`, `App\Core\Enums\SystemRole` y los
DTOs de `App\Core\Data\Authorization`.

La segunda la puso asper-server, y es la que abrió la excepción. Su
`NotificationsModuleServiceProvider` importa **once** eventos de `Payments`,
`Personnel`, `Studies` y `Auth` para mapearlos a sus listeners en una constante
`EVENT_LISTENERS`: es el módulo de notificaciones haciendo exactamente lo que se
espera de un módulo de notificaciones. PHPat, tal como estaba la regla, lo
marcaba entero en rojo. La opción de «arreglarlo» era escribir los once nombres
como strings; en la v2.1.0 se arregló la regla, que era la que estaba mal.

### R6 · `App\Core` no depende de `App\Modules`
Core es el kernel compartido: lo usan todos, no usa a nadie.
> Enforcement: PHPat · `composer analyse` · **Error** · + Pest arch · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: ninguna

Relacionada: R5 (misma frontera, vista desde el otro lado) y R7 (la forma que
tiene que tener lo que se pone en `Core/Contracts`).

**Por qué.** En cuanto `Core` importa un módulo, el contrato deja de ser una
frontera y pasa a ser decoración: ya no puedes borrar el módulo sin romper el
kernel, que es justo lo que la frontera prometía.

**Cicatriz.** Sin cicatriz todavía — pero hasta la v1.1.0 `Core/Contracts`,
`Core/Support` y `Core/Concerns` sólo contenían `.gitkeep`, así que la regla
nunca se había puesto a prueba. La v1.1.0 la estrenó con
`AuthorizationCatalog`, `SystemRole` y los DTOs de `Core/Data/Authorization`, y
la v2.1.0 llenó `Core/Concerns` con los tres traits que un proyecto derivado
copiaba a mano en cada tabla y en cada formulario
(`HandlesDeleteConfirmation`, `RedirectsWithToast`, `HasPublicUuid`). Son la
prueba de fuego de la regla: dependen de Livewire y de koreUi —capa de entrega
compartida— y de ningún módulo.

### R7 · Los contratos de `Core\Contracts` son interfaces
> Enforcement: PHPat · `composer analyse` · **Error** · + Pest arch (`toBeInterfaces`) · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: ninguna

**Por qué.** Una clase concreta en `Contracts` acopla al consumidor con la
implementación de referencia: el módulo cliente hereda su constructor, sus
dependencias y sus bugs.

**Cicatriz.** Sin cicatriz todavía.

### R8 · DTOs en lugar de arrays asociativos entre capas
Los DTOs son `final`, extienden `App\Core\Data\Data` (spatie/laravel-data),
tienen **todas sus propiedades `readonly`** y **sólo** dependen de otros datos:
nada de modelos, facades ni `Illuminate\Http`.
> Enforcement: Pest arch (`final` + `toExtend` + `readonly` + no `Illuminate\Http`) · `./vendor/bin/pest tests/Arch` · **Error** · + PHPat (`canOnly()->dependOn()`) · `composer analyse` · **Error**
> Escape: `arch-accepted`

**Por qué.** Un `array` entre capas no tiene forma: el que lo recibe adivina las
claves y el día que falta una revienta en runtime. Un DTO la declara, la tipa y
la documenta, y `canOnly` impide que se convierta en una fachada con
colaboradores dentro. `readonly` cierra la última rendija: un DTO que se puede
modificar después de construirlo es un objeto con estado disfrazado de dato, y
quien lo recibe ya no sabe si lo que tiene es lo que le mandaron.

**Cómo se verifica el `readonly`.** Con reflexión, en un `test()` normal dentro
de `tests/Arch`, no con `arch()`: `toBeReadonly()` mira la *clase* readonly de
PHP 8.2 y estos DTOs son `final class` con propiedades promovidas
`public readonly`, que es lo que spatie/laravel-data soporta. El test sólo mira
las propiedades declaradas por la propia clase, porque la `Data` de spatie
añade dos suyas (`_additional`, `_dataContext`) que no son readonly.

**Cicatriz.** La auditoría contó **0 clases** extendiendo `Core\Data\Data` y
`app/Modules/Auth/Data/` estaba vacío y sin trackear, mientras la regla 4 de
`CLAUDE.md` los daba por hechos. Se cerró en la v1.1.0. El `readonly` sí estaba
en el skill `kore-action-create` desde entonces, pero no lo verificaba nadie
hasta la v1.2.0.

### R9 · Un módulo se registra en `bootstrap/providers.php`
Con un provider `final` de sufijo `ServiceProvider` que carga rutas,
migraciones, vistas y componentes Livewire.
> Enforcement: Pest arch (`final` + sufijo) · `./vendor/bin/pest tests/Arch` · **Error** · el registro en sí: **Manual** (si falta, el módulo simplemente no existe y sus tests fallan)
> Escape: ninguna

**Por qué.** Es el único punto donde se ve, de un vistazo, qué módulos componen
la aplicación.

**Cicatriz.** `module-pattern.md` mostraba un `bootstrap/providers.php` sin
`UsersModuleServiceProvider`, y quien copiaba la plantilla se quedaba sin el
módulo Users. Corregido en la v1.0.0.

### R10 · Un toggle apagado no registra nada
Cuando `config('kore-app.{x}.enabled')` es `false`, el provider del módulo hace
`return` temprano: ni rutas, ni middleware, ni vistas, ni comandos de dominio.

**Única excepción: el comando que enciende el toggle.** Un módulo opt-in puede
registrar su propio comando de activación *antes* del early return, porque si no
no habría forma de encenderlo desde una instalación limpia. Nada más: ese
comando no carga rutas, ni vistas, ni migraciones.

**Segunda excepción, más pequeña: el namespace de vistas.** Larastan valida
cada `view('docs::x')` contra el `ViewFactory` de la aplicación que arranca
durante el análisis, y en CI el toggle vale su default. Un provider puede
registrar su `loadViewsFrom()` antes del `return` (así lo hace
`DocsModuleServiceProvider`): sin rutas no hay forma de llegar a esas vistas,
así que no expone nada observable.

**Tercera excepción: el namespace de vistas que contiene componentes Blade
anónimos.** Es la segunda otra vez, pero por una razón que no es de comodidad
sino de mecánica del framework, y por eso no se puede saltar. Blade resuelve la
etiqueta `<x-files::slot-upload>` **al compilar** la plantilla que la usa, no al
ejecutarla: un `@if (config('kore-app.files.enabled'))` alrededor no evita nada,
porque para cuando el `if` se evalúa el componente ya tuvo que existir. Con el
`loadViewsFrom` detrás del `return`, la pantalla de edición de usuarios devolvía
un 500 en toda instalación con `FILES_ENABLED=false`. El registro va siempre, y
lo fija un test.

**Nota · los motivos del `loadViewsFrom` son dos, y hay que leerlos los dos.**
La segunda y la tercera excepción son el mismo `loadViewsFrom` con dos razones
distintas —Larastan valida los `view('x::…')` contra los namespaces registrados;
Blade resuelve un componente anónimo al **compilar** la plantilla que lo usa—, y
quien sólo conoce una lo mueve detrás del `return` en cuanto esa una no aplica.
`toggles.md` las cuenta enteras y aquí están las dos a propósito. La v2.4.0 lo
redescubrió: `NotificationsModuleServiceProvider` empezó con el `loadViewsFrom`
dentro del toggle —el módulo no tiene componentes Blade anónimos, así que la
tercera excepción no le aplicaba— y el análisis se puso en rojo con «expects
view-string» en las cuatro vistas del módulo, que es exactamente la segunda. El
registro va siempre.

Nada más pasa por ahí: rutas, middleware, comandos de dominio y traducciones
siguen detrás del `return`.
> Enforcement: **Manual** + el test del toggle (cada toggle tiene el suyo: `TwoFactorToggleTest`, `TenancyToggleTest`, `FilesToggleTest`) · `composer test` · **Error**
> Escape: `arch-accepted`

**Por qué.** Un toggle que registra «sólo un poquito» no es un toggle: deja
rutas colgando, vistas publicadas y middleware corriendo, y el proyecto
derivado que lo apagó descubre el resto por producción. El comando de activación
es la excepción justificada: sin él, `TENANCY_ENABLED=false` sería un callejón
sin salida.

**Cicatriz.** La de la tercera excepción, en la v2.3.0: con el espacio de vistas
`files::` registrado **dentro** del toggle, la suite se puso en rojo en cuanto el
módulo Users estrenó el avatar, y en una instalación real habría sido un 500 en
la pantalla de edición de usuarios con `FILES_ENABLED=false`. El porqué está
contado una sola vez, en [`toggles.md`](toggles.md) y en el docblock de
`FilesModuleServiceProvider`; aquí sólo queda la regla.

`TenancyModuleServiceProvider` sigue siendo el ejemplo canónico de las dos
mitades: `register()` registra `EnableTenancyCommand`
**antes** del `return` —para que `php artisan kore:tenancy:enable` exista con el
toggle apagado— y a partir de ahí no registra absolutamente nada más;
`boot()` hace el early return sin excepciones. `TenancyToggleTest` blinda las
dos mitades: que el comando siga disponible con el toggle apagado, y que ni el
provider de stancl ni las rutas del módulo se registren.

### R11 · Un toggle sólo existe si alguien lo lee
Toda clave de `config/kore-app.php` aparece en al menos un
`config('kore-app.{clave}')` del código.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-accepted`

Relacionada: R12 (el otro modo de que un toggle mienta: existir, tener lector, y
que el lector sea otro `config/*.php`).

**Por qué.** Un toggle fantasma miente sobre lo que el boilerplate hace. Alguien
pone `SCOUT_ENABLED=true` en el `.env` de producción, no pasa nada, y tarda una
semana en descubrir que el paquete ni siquiera está instalado.

**Cicatriz.** La v1.0.0 borró de `config/kore-app.php` **cinco** entradas que no
leía nadie: los bloques `reverb`, `octane`, `search` y `observability` (que
envolvía `SENTRY_ENABLED` y `PULSE_ENABLED`), más la clave `tenancy.mode`.
Reverb, Octane y Scout pasaron a ser módulos opcionales documentados; el modo
`single-db`/`multi-db` se decide en `config/tenancy.php`; Sentry se activa con
`SENTRY_LARAVEL_DSN` y Pulse con `PULSE_ENABLED` en `config/pulse.php`.

Hubo un sexto candidato que **no** se borró: `auth.two_factor` tampoco tenía
lector real, pero en vez de eliminarlo se convirtió en un toggle de verdad
—sigue vivo en `config/kore-app.php`— y su lector es
`FortifyServiceProvider::configureTwoFactorFeature()`. Ésa es la cicatriz de
R12, y la diferencia importa: un toggle sin lector se borra **o** se conecta,
nunca se deja a medias.

### R12 · Un `config/*.php` no lee otro `config/*.php`
Si un paquete tiene que reaccionar a `kore-app`, su config se muta desde el
`register()` del provider del módulo.
> Enforcement: **Manual** (+ el test del toggle afectado) · **Error** cuando lo hay
> Escape: ninguna

Relacionada: R11 (un toggle sin lector) y R17 (`env()` fuera de `config/`). El
incidente de `AUTH_2FA_ENABLED` las tocó a las tres; cada regla cuenta su parte.

**Por qué.** Los archivos de `config/` se cargan en orden alfabético: `fortify`
va antes que `kore-app`, así que `config('kore-app.…')` dentro de
`config/fortify.php` es `null`. El error es silencioso: el toggle parece
funcionar porque el `env()` de respaldo devuelve lo mismo.

**Cicatriz.** La parte que le toca a R12 es **quién tenía que leer el toggle**.
`config('kore-app.auth.two_factor')` no lo leía nadie porque el que debía
leerlo era `config/fortify.php`, y desde ahí siempre habría valido `null`:
`fortify` se carga antes que `kore-app`. La solución no fue arreglar la lectura
sino moverla fuera de `config/`, al `register()` del provider:
`FortifyServiceProvider::configureTwoFactorFeature()` añade o quita la feature
`twoFactorAuthentication` según el toggle, y corre antes del `boot()` en el que
Fortify publica sus rutas. v1.0.0.

**Nota · una clave con punto no se lee con `config('archivo.a.b')`.** Es el otro
modo silencioso de que una configuración devuelva `null`, y no tiene que ver con
el orden de carga sino con `Arr::get()`: el helper parte la cadena por los
puntos, así que `config('kore-settings.defaults.organization.name')` busca la
clave `organization`, luego `name`, y no encuentra nada — mientras que el array
real tiene **una sola** clave literal `'organization.name'`. Se lee el array
entero y se indexa:

```php
$defaults = config('kore-settings.defaults');          // array<string, mixed>
$name = $defaults['organization.name'] ?? null;        // sí
$name = config('kore-settings.defaults.organization.name'); // null, en silencio
```

Cicatriz: `config/kore-settings.php` en la v2.4.0, cuyas claves son
`organization.name`, `organization.tax_id`… con el punto **dentro** del nombre
porque son el identificador del ajuste en la tabla `settings`, no una jerarquía.

### R60 · Un toggle no apaga el catálogo de autorización
`ModulesSeeder` y `Module::specialPermissions()` siembran sus módulos, roles y
permisos **siempre**, mire lo que mire el `.env`. Ninguno de los dos lee
`config('kore-app.…')` ni `env()`.
> Enforcement: `kore:arch:check` (`checkAuthorizationCatalogIgnoresToggles`) · `composer arch` · **Error**
> Escape: ninguna

Relacionada: R10, de la que ésta es la otra mitad. R10 dice que un toggle
apagado no registra **comportamiento**; R60 dice que tampoco borra **datos de
estructura**, que es la misma frontera que ya separa las migraciones (una tabla
de un módulo apagado se migra igual, ver [`toggles.md`](toggles.md)).

**Por qué.** Un permiso es esquema, no capacidad. Si el seeder lo siembra sólo
con el toggle encendido, encender el módulo en producción deja de ser una
variable de entorno y pasa a ser «cambia el `.env`, despliega, y acuérdate de
volver a sembrar»; y hasta que alguien se acuerde, la pantalla existe, el
middleware `permission:` la protege y **nadie** tiene el permiso que la abre —ni
siquiera el administrador—. El fallo aparece como un 403 que no se puede
explicar mirando los roles, porque el permiso no está en la base para
asignarlo.

Al revés no cuesta nada: un permiso sembrado cuyo módulo está apagado no abre
nada, porque no hay ruta que proteger.

**Cicatriz.** Preventiva, y con dos casos que la hicieron explícita. En la
v2.4.0 las invitaciones (`invitations.manage`) y los webhooks
(`webhooks.manage`) se sembraron **fuera** del toggle a propósito, y lo mismo
hizo `settings.manage` del módulo Platform, que ni toggle tiene. Los tres lo
hicieron bien; la regla existe para que el cuarto no tenga que volver a
razonarlo, porque poner el `if` es exactamente lo que parece correcto cuando se
escribe el seeder.

### R54 · Toda respuesta de la API pasa por el contrato de Core
Los controllers de `App\Modules\*\Http\Controllers\Api` extienden
`App\Core\Http\Api\Controllers\ApiController`; sus resources
(`Http\Resources\Api`) extienden `BaseApiResource`; sus form requests
(`Http\Requests\Api`) extienden `BaseApiRequest`; y **ningún** error de
`api/*` se rinde a mano: lo traduce `ApiExceptionRenderer`. El éxito viaja como
`{ data, meta? }` y el fallo como `{ error: { code, message, details? } }`.
> Enforcement: Pest arch (`tests/Arch/ArchitectureTest.php`, tres `test()` con `is_subclass_of` sobre un glob, tolerantes a namespaces vacíos) · `./vendor/bin/pest tests/Arch` · **Error** · + Pest (`tests/Feature/Api/ApiExceptionRendererTest.php`, un caso por código canónico) · `composer test` · **Error**
> Escape: `arch-accepted` (ver §Válvulas)

Relacionada: R8 —un DTO es la forma de un dato *dentro* de la aplicación y un
resource la forma con la que **sale**— y R28, que pone el rate limit sobre estos
mismos endpoints.

**Por qué.** Un contrato de API no se documenta: se hereda. En cuanto cada
endpoint decide su propio sobre, el cliente necesita saber de qué endpoint viene
cada respuesta para saber cómo leerla, y eso no se arregla con un doc — se
arregla el día que alguien reescribe los treinta endpoints a la vez, que es un
día que no llega. Heredarlo de una clase base hace que la decisión se tome una
vez, que el arch test la mantenga, y que un endpoint nuevo salga bien por
defecto en vez de por disciplina.

El envelope de error, además, es lo que hace que la API sea **operable**: un
`code` canónico (`throttled`, `validation_failed`, `conflict`) es lo que un
cliente puede programar; un status HTTP a secas no distingue «tu token caducó»
de «este recurso ya no existe», y un mensaje en prosa cambia el día que alguien
mejora la redacción. Y el mensaje lo pone el contrato y no la excepción a
propósito: el de un `ModelNotFoundException` lleva dentro el FQCN del modelo y
el id buscado, y el de una `AuthorizationException` viene en inglés desde el
Gate.

**Cicatriz.** Triple, y las tres versiones de la misma.

En **Notarium**, `app/Http/Exceptions/ApiExceptionRenderer.php`,
`app/Http/Requests/Api/V1/BaseApiRequest.php` y
`app/Http/Resources/Api/V1/BaseApiResource.php` inventaron el contrato entero
—envelope, códigos, `details`, paginación por cursor— y lo inventaron bien. En
**asper-server**, que salió del mismo boilerplate, no existe ninguno de los
tres: su API responde con la forma que cada controller decida. Dos hijos del
mismo padre, la misma necesidad, y una sola de las dos soluciones; la otra
todavía no ha aparecido, y cuando aparezca será distinta.

Y en el propio boilerplate, hasta la v2.1.0, la única ruta de API era
`GET /api/user` devolviendo `fn (Request $request) => $request->user()`: el
modelo Eloquent a pelo, serializado con todos los atributos que tuviera la tabla
el día del `git pull` —los `#[Hidden]` del modelo eran lo único entre la API y
el `two_factor_secret`— y sin sobre, así que el primer endpoint que hubiera
devuelto una colección habría tenido que inventarse otro formato. Se cerró en la
v2.2.0 con `App\Core\Http\Api` y `UserMeResource`.

### R58 · Un API Resource no declara un campo llamado `data`
En `Http/Resources/Api/`, la lista que devuelve `toArray()` no puede tener una
clave `data`. Si el recurso necesita publicar un objeto libre, se llama de otra
cosa: `payload`, `attributes`, `meta_*`.
> Enforcement: `kore:arch:check` (`checkApiResourcesHaveNoDataField`) · `composer arch` · **Error**
> Escape: ninguna

Relacionada: R54, de la que ésta es una consecuencia mecánica. R54 fija el sobre;
R58 impide que un campo se disfrace de sobre.

**Por qué.** `data` es el nombre del envoltorio, y el framework lo comprueba por
el nombre. `Illuminate\Http\Resources\Json\ResourceResponse::wrap()` mira si la
lista que devolvió el recurso ya contiene la clave del envoltorio y, si la
encuentra, da por hecho que el recurso se envolvió solo y no vuelve a envolver.
Un campo llamado `data` la engaña: la respuesta sale **sin** envelope y con
`meta` colgando al lado en vez de dentro.

Y no hay error, ni aviso, ni excepción. El endpoint responde 200, el recurso
está entero, y lo único que pasa es que la forma es otra: el cliente que hacía
`respuesta.data.read` lee `null` y el que hacía `respuesta.data` recibe el valor
del campo. Es el tipo de fallo que un test de contrato encuentra y una revisión
a ojo no, porque el JSON «se ve bien».

**Cicatriz.** `NotificationResource`, v2.4.0. Laravel guarda el contenido de una
notificación en una columna JSON llamada `data`, así que el recurso empezó
publicándola con su nombre; el test del contrato empezó a leer `null` en
`data.read` y el envelope había desaparecido. El campo se llama **`payload`**, y
el porqué está escrito en el docblock del recurso para que nadie lo «arregle»
devolviéndole el nombre original.

### R59 · El middleware de una ruta se declara en un solo array
Una sentencia de rutas llama a `middleware()` **una vez**, con todo dentro:
`Route::middleware(['web', 'auth', 'verified', 'permission:x'])`. Nada de
encadenar un segundo `->middleware(...)`.
> Enforcement: `kore:arch:check` (`checkRouteMiddlewareIsDeclaredOnce`) · `composer arch` · **Error**
> Escape: `arch-accepted`

Relacionada: R23. Las dos hablan de la misma puerta desde lados opuestos: R59
se ocupa de que el middleware de la ruta llegue entero, y R23 de que el
componente Livewire vuelva a autorizar porque `/livewire/update` no pasa por él.

**Por qué.** `Illuminate\Routing\RouteRegistrar::middleware()` **sustituye** el
atributo en vez de acumularlo. Un
`Route::middleware(['web', 'auth'])->middleware('permission:x')` no protege con
las tres cosas: protege con `permission:x` y **pierde el grupo `web` entero**, y
con él `StartSession`, `VerifyCsrfToken` y —la que duele—
`SubstituteBindings`.

El síntoma es lo que hace falta la regla. No es un 403 ni un 404, que apuntarían
a las rutas: sin `SubstituteBindings`, el parámetro `{endpoint}` llega al
controller como el **string** de la URL y no como el modelo, así que la
excepción salta más abajo —en la policy, en el `->uuid` de un accesor, en la
vista— con un mensaje que no menciona ni middleware ni rutas. Se pierde media
tarde antes de mirar el archivo correcto.

**Cómo se verifica.** Sentencia a sentencia, con `token_get_all()` en vez de una
expresión regular: hay que cortar también en `{` y en `}` para que el
`Route::middleware(...)` de dentro de un `group()` no se cuente junto al de
fuera, y hay que ignorar comentarios y literales para que un `'/{user}'` no
parta la sentencia ni un docblock que explica la regla la infle.

**Cicatriz.** El módulo Webhooks, v2.4.0. Las cuatro pantallas llevaban
`permission:webhooks.manage` encadenado detrás del grupo, y `/webhooks/{endpoint:uuid}`
reventaba con un error que hablaba de un `string` donde se esperaba un modelo. El
docblock de `app/Modules/Webhooks/Routes/web.php` lo cuenta en el sitio donde se
volvería a escribir.

---

## §2 · Código

### R13 · `declare(strict_types=1)` en todo PHP de `app/`
> Enforcement: Pest arch (`toUseStrictTypes`) · `./vendor/bin/pest tests/Arch` · **Error** · + Pint (`declare_strict_types`) · `composer lint` · **Error**
> Escape: ninguna

**Por qué.** Sin `strict_types`, PHP convierte `"12 usuarios"` en `12` y un
`?int` en `0`. El bug aparece tres capas más abajo, con otro valor.

**Cicatriz.** Sin cicatriz todavía: la auditoría verificó el 100 % de
cumplimiento. La regla existe para que siga siendo así.

### R14 · `final class` por defecto
Salvo que se necesite herencia explícita (`App\Core\Actions\Action`,
`App\Core\Data\Data`, `App\Models\User`).
> Enforcement: Pest arch (Actions, Data, Events, Rules, Policies, Providers) · `./vendor/bin/pest tests/Arch` · **Error** · el resto: **Manual**
> Escape: `arch-accepted`

Relacionada: R1, R8, R9 y R25, que exigen `final` cada una en su tipo de clase.
Aquí está la regla general y el porqué; allí, el verificador concreto. Si algún
día se añade un tipo nuevo, la regla ya existe: lo que falta es su `arch()`.

**Por qué.** `final` es la forma barata de decir «esto no es un punto de
extensión». Quitarlo cuando haga falta cuesta un carácter; recuperar el control
de una clase que ya tiene seis hijos, no.

**Cicatriz.** Sin cicatriz todavía.

### R15 · Type hints completos
Parámetros, retornos y propiedades tipados. `mixed` es una decisión, no un
descuido.
> Enforcement: Larastan nivel 8 · `composer analyse` · **Error**
> Escape: `arch-exception` (con `@phpstan-ignore` al lado, ver §Válvulas)

**Por qué.** El nivel 8 es lo que hace que el resto de reglas se puedan
verificar: sin tipos, ni PHPat ni disallowed-calls saben sobre qué clase se
está llamando un método.

**Cicatriz.** Sin cicatriz todavía.

### R16 · `CarbonImmutable` por defecto
`Date::use(CarbonImmutable::class)` en `AppServiceProvider`; Pint fuerza
`DateTimeImmutable` sobre `DateTime`.
> Enforcement: Pint (`date_time_immutable`) · `composer lint` · **Error** · el uso: **Manual**
> Escape: `arch-accepted`

**Por qué.** `$fecha->addDay()` sobre un `Carbon` mutable modifica el objeto que
te pasaron, no una copia. El bug clásico: un rango de fechas que se desplaza
solo dentro de un bucle.

**Cicatriz.** Sin cicatriz todavía.

### R17 · `env()` sólo dentro de `config/`
> Enforcement: disallowed-calls (`kore.r17`) · `composer analyse` · **Error** · + Pest arch · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: `arch-exception`

Relacionada: R12 (el mismo incidente, visto desde el otro lado).

**Por qué.** Con `php artisan config:cache` —que es lo normal en producción—
`env()` devuelve `null` fuera de `config/`. El fallo no revienta: la app arranca
con la feature apagada y nadie se entera.

**Cicatriz.** La parte que le toca a R17 es **el `env()` que tapó el fallo**.
`config/fortify.php` llamaba a `env('AUTH_2FA_ENABLED')` directamente, así que
en desarrollo —sin `config:cache`— el 2FA se encendía y se apagaba como si el
toggle funcionara. El primer `php artisan config:cache` lo habría dejado
apagado en silencio, con el toggle diciendo `true`. Un `env()` fuera de
`config/` no falla: miente hasta que se cachea. v1.0.0.

### R18 · Sin helpers de depuración
Nada de `dd`, `dump`, `var_dump`, `ray` en `app/`.
> Enforcement: disallowed-calls (`kore.r18`) · `composer analyse` · **Error** · + Pest arch · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: ninguna

**Por qué.** Un `dd()` olvidado en un endpoint de API devuelve un 200 con el
volcado del objeto —incluidos hashes y tokens— a cualquiera que lo llame.

**Cicatriz.** Sin cicatriz todavía.

### R19 · El actor se pasa por constructor
Nada de `auth()`, `request()`, `session()` ni `cookie()` en `Actions`, `Models`,
`Data`, `Rules` ni `App\Core`. Quien llama autoriza y pasa el `User`.
> Enforcement: disallowed-calls (`kore.r19`) · `composer analyse` · **Error** · + PHPat (no dependen de la capa de entrega) · **Error**
> Escape: `arch-exception`

**Por qué.** Dentro de un job en cola, de un comando artisan o de un seeder,
`auth()->user()` es `null` y `request()` es una petición sintética. La Action no
falla: hace lo incorrecto en silencio, normalmente saltándose la autorización.

**Cicatriz.** Las reglas anti-escalada `GrantablePermission` y `GrantableRole`
(v1.1.0) nacieron con el actor por constructor precisamente por esto: así se
pueden testear sin sesión y usar desde consola. Lo mismo hicieron las tres
Actions de Users.

### R20 · `abort*()` sólo en la capa Http
`abort()`, `abort_if()` y `abort_unless()` viven en controllers, componentes
Livewire, middleware y rutas. Nunca en Actions, Models, Data o Rules.
> Enforcement: disallowed-calls (`kore.r20`) · `composer analyse` · **Error**
> Escape: `arch-exception`

Relacionada: R19, de la que ésta es el caso concreto más frecuente —`abort()`
es, como `auth()`, una dependencia implícita del ciclo de petición HTTP—. Se
mantiene aparte porque tiene su propia entrada en `phpstan-disallowed.neon`
(`kore.r20`) y su propia lista de rutas permitidas.

**Por qué.** `abort()` lanza una `HttpException`: una Action que aborta sólo se
puede llamar desde una petición HTTP, y desde una cola produce un fallo de job
en lugar de una excepción de dominio con sentido.

**Cicatriz.** Sin cicatriz todavía. Hoy el boilerplate tiene exactamente dos
llamadas, las dos en capa Http y por eso permitidas:
`TableUsers::confirmDelete()` usa `abort_if` como guarda de auto-borrado, y
`SocialiteController` usa `abort_unless` para rechazar un proveedor que el
toggle no ha habilitado.

### R21 · `DB::table()` sólo en migraciones y seeders
> Enforcement: disallowed-calls (`kore.r21`) · `composer analyse` · **Error**
> Escape: `arch-exception`

**Por qué.** El query builder crudo se salta casts, scopes globales, eventos de
modelo y el activity log. Es exactamente lo que quieres en una migración y
exactamente lo que no quieres en el resto de la app.

**Cicatriz.** Sin cicatriz todavía.

### R22 · La E/S remota no vive en la capa de entrega
`Http::*` y `Mail::send|raw` no se llaman desde un controller ni desde un
componente Livewire: van en una Action y, si tardan, en un job.
> Enforcement: disallowed-calls (`kore.r22`) · `composer analyse` · **Error**
> Escape: `arch-exception`

Relacionada: R4, de la que ésta es un caso concreto y **verificable**. R4 dice
«sin lógica de negocio en la capa de entrega» y su tamaño se revisa a ojo; R22
recorta el trozo que una herramienta sí puede ver —dos familias de llamadas— y
lo hace fallar el build.

**Por qué.** Una llamada HTTP dentro de `/livewire/update` deja la petición del
usuario colgada de un tercero, y el test del componente necesita red o un fake
en cada caso.

**Cicatriz.** Sin cicatriz todavía.

---

## §3 · Seguridad

### R23 · La autorización vive dentro del componente Livewire
Todo método público que escriba llama a `authorize()`, `can()` o `Gate::`. El
check reconoce como escritura los prefijos `save*`, `store*`, `create*`,
`update*`, `delete*`, `destroy*`, `remove*`, `confirm*`, `toggle*`, `add*`,
`send*`, `sync*`, `assign*`, `approve*` e `import*`.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-accepted` (flujo de invitado, sin nada que autorizar) o `arch-exception` (deuda con fecha)

**Por qué.** Las llamadas Livewire viajan por `POST /livewire/update`, una ruta
que **no** pasa por el middleware `permission:` del módulo. El `->hidden()` de
un botón y el `@can` de una Blade son cosmética de cliente: se editan desde la
consola del navegador.

**Cicatriz.** Dos vulnerabilidades críticas de la v1.0.0.
`TableUsers::confirmDelete()` sólo comprobaba que no te borraras a ti mismo —el
bloqueo real estaba en el `hidden()` del RowAction—, así que alguien con
`users.view` podía borrar a un superadmin. Y `FormComponent::save()` no
autorizaba en absoluto.

Y una segunda, de la propia regla: hasta la v1.2.0 la lista de prefijos era sólo
el CRUD literal, así que `MagicLink::sendCode()` —que manda un correo a quien lo
pida— no lo miraba nadie. Al ampliar la lista saltó, y la respuesta correcta no
fue añadir un `authorize()` sino escribir por qué no lo lleva: es un flujo de
invitado, no hay sesión que autorizar, y lo que lo protege es el rate limit de
R28. Eso es exactamente para lo que existe `arch-accepted`.

**Nota · los hooks `updated*` / `updating*` de Livewire también entran por
`/livewire/update`, y el check los marca a propósito.** Un
`updatedSelectedRole()` no lo llama tu código: lo llama Livewire cuando el
cliente manda un valor nuevo, y el cliente decide cuándo y con qué. Es un método
público más, con la misma superficie que un `save()`, y por eso la lista de
prefijos de escritura (`update`) lo captura. La salida **no** es una válvula.
Son dos, según lo que haga el hook:

- **Autoriza dentro**, aunque el hook sólo lea. Es el caso de
  `TableNotifications::updatedCategory()` y `updatedOnlyUnread()` en la v2.4.0:
  son dos filtros que llaman a `authorize('viewAny', …)` antes de `resetPage()`.
  No es ceremonia —cuesta una llamada al Gate— y evita la discusión de si el
  hook «lee» o «escribe», que es exactamente la que se pierde dentro de seis
  meses.
- **Si no escribe, renómbralo.** `Mx\Http\Livewire\PostalCodeField` tenía un
  `updatedPostalCode()` que sólo consulta el catálogo y rellena el formulario;
  se llama `lookup()` y el hook se limita a delegar. El nombre pasó a decir la
  verdad, que es lo que la regla quería en primer lugar.

### R24 · `#[Locked]` en toda propiedad pública identificadora
En `Forms/` y en `Http/Livewire/`, cualquier `public $id`, `public $model` o
`public $algoId` lleva `#[Locked]`.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-exception`

**Por qué.** Livewire rehidrata las propiedades públicas desde el payload del
cliente. Sin el candado, el navegador decide sobre qué registro opera el
servidor.

**Cicatriz.** `UserForm::$id` era `public ?int` sin `#[Locked]`, y con
`updateOrCreate(['id' => …])` detrás. Cualquiera con `users.create` podía fijar
`form.id` por `/livewire/update` y sobrescribir email, contraseña, rol y
permisos de **cualquier** usuario. Escalada directa, agravada por el
`Model::unguard()` global de entonces (v1.0.0, crítica).

### R25 · La Policy es el único punto de decisión
La regla de «¿puede este usuario?» se escribe una vez, en la Policy del módulo,
y todo lo demás la consulta. Las Policies son `final` y con sufijo `Policy`.
> Enforcement: Pest arch (`final` + sufijo) · `./vendor/bin/pest tests/Arch` · **Error** · el uso: **Manual** + R23
> Escape: `arch-accepted`

Relacionada: R23, que es la verificación de esta regla en el único sitio donde
se puede automatizar (que el componente Livewire consulte a alguien). Que la
consulta acabe en la Policy y no en un `if` a mano sigue siendo revisión humana.

**Por qué.** Con dos fuentes de verdad, una se actualiza y la otra no. La que se
olvida siempre es la del camino menos visitado.

**Cicatriz.** La auditoría de septiembre de 2026 lo dejó por escrito: «La
`UserPolicy` existe, está registrada y es correcta, pero **ningún componente
Livewire la invoca**» (`docs/audit/2026-09-02-auditoria-y-roadmap.md`). Escribir
la regla y no consultarla desde ningún sitio cuesta lo mismo que no escribirla.

### R26 · Nadie concede un rol ni un permiso que no tiene
Medido en permisos, no en nombres de rol, para que un rol nuevo quede cubierto
solo. El superadmin es la única excepción.
> Enforcement: tests (`PrivilegeEscalationTest`) · `composer test` · **Error**
> Escape: ninguna

**Por qué.** Si el formulario de alta acepta cualquier rol y cualquier permiso,
el permiso `users.create` es en la práctica el permiso `*`.

**Cicatriz.** Escalada de privilegios de severidad alta cerrada en la v1.1.0:
cualquiera con `users.create` + `users.edit` podía crear una cuenta con
**cualquier** rol y **cualquier** permiso del sistema —incluidos los que él
mismo no tenía— y entrar con ella. Lo cierran `GrantablePermission` y
`GrantableRole`.

### R27 · Mass assignment explícito
Sin `Model::unguard()` global. Cada modelo declara su lista blanca con el
atributo `#[Fillable]` de Laravel 13 (antes la propiedad `$fillable`).
> Enforcement: disallowed-calls (`kore.r27`) · `composer analyse` · **Error** · + `MassAssignmentTest` · `composer test` · **Error**
> Escape: ninguna

**Por qué.** `unguard()` global desactiva la protección de **todos** los
modelos, incluidos los de vendor. Convierte cualquier `fill($request->all())`
descuidado en una escalada de privilegios.

**Cicatriz.** Estaba activo hasta la v1.0.0 y es lo que hizo explotable la
escalada de R24: sin él, `UserForm` no habría podido sobrescribir el rol.

### R28 · Rate limit en todo endpoint que envía correo
Y respuesta genérica: nunca confirmes si un email existe.
> Enforcement: tests (`MagicLinkTest`, `PasswordResetTest`, `ApiRateLimitTest`) · `composer test` · **Error** · el diseño: **Manual**
> Escape: `arch-accepted`

**Por qué.** Un formulario de «recuperar contraseña» sin límite es un enviador
de correo gratuito a nombre de tu dominio, y con `exists:users,email` es además
un enumerador de usuarios.

**Cicatriz.** `MagicLink::sendCode()` no tenía rate limit y validaba con
`exists:users,email`. El rate limit de Nginx cubría `/magic-link` pero no
`/livewire/update`, que es por donde viaja la llamada real. Cerrado en la
v1.0.0 con 5 envíos / 5 min por email + IP y respuesta genérica.

### R46 · Las cabeceras de seguridad las emite la aplicación
`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS y la CSP salen de
`config/security.php` a través de `App\Http\Middleware\SecurityHeaders`, no del
servidor web. Un origen nuevo se añade a ese config, nunca al `nginx.conf`, y
la CSP se estrena en `report-only`.
> Enforcement: tests (`SecurityHeadersTest`) · `composer test` · **Error**
> Escape: `arch-accepted`

**Por qué.** Una cabecera que pone el hosting no viaja con el código: no está en
el diff, no la ve el review, no la cubre ningún test y desaparece entera el día
que el despliegue cambia de sitio. Puesta en la aplicación es un artefacto más
—se prueba, se versiona y funciona igual en Docker, en Forge, en Laravel Cloud
o en un `artisan serve`—, y la política queda en un solo archivo donde se puede
leer entera de una vez. El servidor puede seguir emitiéndolas como defensa en
profundidad para los estáticos que sirve sin pasar por PHP, pero la fuente de
verdad es el config.

**Cicatriz.** Hasta la v1.2.0 las cabeceras vivían **sólo** en
`docker/nginx/nginx.conf`, dentro del `server` block del contenedor. Cualquier
despliegue fuera de ese Docker —el que hace un derivado del boilerplate en un
hosting compartido, o el `artisan serve` de una demo— salía a producción sin
`X-Frame-Options`, sin `X-Content-Type-Options` y sin CSP, y nada lo decía. En
la v1.3.0 la CSP se retiró del Nginx (dos CSP simultáneas se intersecan, y la
más restrictiva gana en silencio) y pasó a `config/security.php`.

### R47 · `APP_DEBUG=true` no arranca en producción
`AppServiceProvider::refuseToBootWithDebugInProduction()` lanza `RuntimeException`
si `app()->isProduction()` y `config('app.debug')` es `true`. La aplicación no
levanta.
> Enforcement: tests (`ProductionConfigTest`) · `composer test` · **Error**
> Escape: ninguna

Relacionada: R17, que es la otra mitad de lo mismo —el `.env` sólo se lee desde
`config/`, y aquí se comprueba que lo que se leyó no es una bomba.

**Por qué.** Con `APP_DEBUG=true`, la pantalla de error de Laravel vuelca el
`.env` **entero** junto al stack trace: `APP_KEY`, credenciales de base de
datos, tokens de terceros, el secreto de `/health/json`. No hace falta un
atacante: basta una excepción cualquiera y alguien mirando. Y no hay ninguna
señal de que esté pasando —la aplicación funciona perfectamente— hasta que se ve
el volcado. Un fallo tan barato de cometer (un `.env` copiado de local, un
`config:cache` que no se rehízo) y tan caro de detectar sólo se puede atajar
haciéndolo ruidoso: si la aplicación no arranca, el despliegue se para y alguien
lo lee.

**Cicatriz.** Sin cicatriz todavía. La regla es preventiva a propósito: la
cicatriz de ésta es una filtración de credenciales, y no es de las que se
esperan a tener.

### R55 · Toda URL de un archivo privado sale de `FileStore::url()`
Fuera de `app/Modules/Files/` nadie construye a mano la dirección de un archivo:
ni `Storage::url()`, ni `Storage::temporaryUrl()`, ni el `getUrl()`,
`getTemporaryUrl()` o `getFullUrl()` de media-library. Se pide a
`App\Core\Contracts\FileStore::url()`.
> Enforcement: `kore:arch:check` (`checkFileUrlsComeFromStore`) · `composer arch` · **Error**
> Escape: `arch-accepted` (un módulo que sirve assets públicos suyos, por ejemplo)

Relacionada: R56, que es la otra mitad del contrato de archivos —ésta gobierna
cómo salen, aquélla cómo dejan de estar—, y R25: la URL firmada es el resultado
de una decisión de la policy, no un atajo para saltársela.

**Por qué.** Emitir la URL de un archivo privado es afirmar dos cosas a la vez, y
las dos se olvidan por separado. La primera es que quien la va a usar ya pasó
por la policy del dueño: la firma **es** la autorización, porque la ruta que
sirve el archivo no lleva `auth` —no puede: la abre un `<img>`—. La segunda es
que el `v` que invalida la caché del navegador va **dentro** de la firma; fuera,
cambiarlo rompe la firma y el archivo deja de servirse, y no ponerlo deja al
usuario mirando la foto vieja hasta que vacíe la caché.

Un `Storage::temporaryUrl()` escrito en una Blade no cumple ninguna de las dos, y
lo peor es que funciona: la imagen se ve, el bug es que también la ve quien no
debería.

**Cómo se verifica.** Por texto, sobre los `.php` de `app/` y de
`resources/views/` —también las Blade, que es donde Notarium lo hacía— menos los
del propio módulo Files. Dos límites que conviene conocer antes de fiarse: una línea con
`public_path(` queda exenta —un logo del proyecto no es un archivo de usuario— y
`getUrl()` sólo cuenta en un archivo que mencione «media» en alguna parte,
porque es también el nombre del accesor de un nodo de CommonMark
(`DocLinkExtension` lo llama para reescribir los enlaces de `/docs`) y un
archivo que no habla de media no puede tener un `Media` en la mano. Los otros
cinco patrones no son ambiguos y se miran siempre.

**Cicatriz.** Doble, y de los dos derivados. **Notarium** pintaba
`getTemporaryUrl()` directamente desde las Blade, en cada sitio donde se
enseñaba un documento: la decisión de exponer un archivo estaba repartida por la
capa de plantillas, donde no la ve ningún test. Y **asper-server** dejó escrito
en `MediaUrl` por qué el `v` va dentro de la firma y no como query aparte; lo
dejó escrito porque lo descubrió al revés.

---

## §4 · Datos

### R29 · Toda migración define `down()`
Incluidas las publicadas por un paquete. Y ese `down()` se ejecuta: deja la
base como estaba antes del `up()`.
> Enforcement: `kore:arch:check` (que el método exista) · `composer arch` · **Error** · + Pest (`tests/Feature/MigrationsAreReversibleTest.php`, que el método funcione) · `composer test` · **Error**
> Escape: `arch-accepted` (una migración de datos irreversible es una decisión legítima; se escribe)

**Por qué.** Una migración sin `down()` convierte cualquier rollback en un dump
manual a las tres de la mañana. Pero **un `down()` que existe y no funciona es
peor que ninguno**: el que falta lo ves en el `git grep`, el que está roto lo
descubres a mitad del rollback, con la mitad de las tablas ya caídas y sin
camino de vuelta. Por eso la regla tiene dos verificadores y no uno: el textual
sólo demuestra que alguien escribió el método.

**Cicatriz.** Doble. Al escribir la regla (v1.2.0), tres migraciones publicadas
de vendor —`one_time_passwords`, `activity_log` y las tablas de
`spatie/laravel-health`— no definían `down()`; se les añadió. Pero ese `down()`
escrito a mano no lo corrió nadie hasta la v1.3.0, cuando
`MigrationsAreReversibleTest` hizo el ciclo completo `migrate:fresh` →
`migrate:reset` → `migrate` por primera vez. Esa vez salieron todos vivos; la
regla se queda porque el que no se ejecuta es el que se pudre.

### R30 · Sin Eloquent en Blade
Nada de `::query()`, `::where(`, `::count(`, `::all(`, `::find(` ni
`\App\Models` dentro de un `.blade.php`. El componente prepara DTOs.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-exception`

Relacionada: R4, de la que ésta es el caso extremo —la capa de entrega no es ya
que tenga lógica, es que la tiene en la plantilla— y, a diferencia de R4,
verificable por texto.

**Por qué.** Una consulta dentro de una vista no aparece en los tests, no
aparece en el profiler donde la buscas y es el origen habitual de los N+1 que
sólo se ven en producción.

**Cicatriz.** `dashboard.blade.php` hacía `User::count()`, `Permission::count()`
y `Module::where(...)->count()` dentro de un bloque `@php`. En la v1.1.0 el
dashboard pasó a ser un componente Livewire con `DashboardStatData`.

### R48 · Producción hace copias de seguridad cifradas y monitorizadas
Con `BACKUP_ENABLED=true`, el scheduler corre `backup:clean`, `backup:run` y
`backup:monitor` a diario, el zip va cifrado con `BACKUP_ARCHIVE_PASSWORD` y el
monitor —y el check `BackupsCheck` de `/health`— vigilan **exactamente** los
discos a los que se escribe (`BACKUP_DISKS`), no una lista aparte.
> Enforcement: tests (`BackupTest`) · `composer test` · **Error** · que producción encienda el toggle y ponga la contraseña: **Manual**
> Escape: `arch-accepted`

Relacionada: R10 y R11 (es un toggle más, con su provider que no registra nada
apagado y su lector real).

**Por qué.** Un backup que nadie vigila no existe: se descubre que lleva meses
fallando el día que hace falta. Y un zip sin cifrar en un bucket es la tabla de
usuarios en claro fuera del servidor. Por eso el destino y el monitor salen de
la misma variable, y por eso el test comprueba que el archivo generado está
cifrado de verdad, no que la opción esté puesta.

**Cicatriz.** Sin cicatriz todavía. La auditoría de septiembre de 2026 encontró
un stack Docker con volúmenes persistentes de MySQL y `storage/` y ninguna copia
programada de ninguno de los dos.

### R53 · Al modificar una columna se repiten todos sus atributos
Un `->change()` reescribe la definición completa: todo atributo que no vuelvas a
escribir —`nullable()`, `default()`, `unsigned()`, `comment()`, la longitud— se
pierde. Antes de escribir la migración se lee el esquema real
(`php artisan db:table {tabla}` o `Schema::getColumns()`), y el `down()` repite
los valores **originales**.
> Enforcement: **Manual** · **Warning** · + el skill `kore-migration-change`, que obliga a leer el esquema antes de escribir
> Escape: ninguna

Relacionada: R29, que exige el `down()` y comprueba que funciona. R53 se ocupa
de lo que ese `down()` tiene que decir.

**Por qué.** El fallo no aparece en la migración: aparece en el `INSERT` de la
semana siguiente, cuando la columna que era `nullable` ya no lo es y el
formulario que no manda ese campo revienta en producción. Y aparece lejos del
commit que lo causó, con un stack trace que no menciona la migración.

**Por qué es Manual.** Se estudió un check textual —marcar toda migración con
`->change()` que no lleve un comentario listando los atributos conservados— y se
descartó: obligaría a escribir un comentario ceremonial en cada migración
legítima sin comprobar que lo que dice sea verdad, que es justo lo que R41
prohíbe. Sólo el esquema real sabe qué tenía la columna antes, y leerlo es un
paso del proceso, no un lint. Por eso lo que se automatiza aquí es el
**procedimiento**: el skill lee el esquema, enseña los atributos que conserva y
pide confirmación antes de migrar.

**Cicatriz.** Notarium perdió atributos en un `->change()` y la respuesta fue
escribirse un agente propio (`.claude/agents/notarium-migration.md`) cuya única
misión era leer el esquema antes de generar la migración. Un proyecto derivado
que tiene que inventarse una herramienta para no tropezar con el framework es la
señal de que al padre le faltaba la regla; en la v2.1.0 subió, con el skill
`kore-migration-change` detrás.

**Nota · un atributo de Eloquent no puede llamarse como una propiedad del
modelo.** Es la misma familia de fallo silencioso que R53, un escalón más
arriba: la columna se llama igual que algo que `Illuminate\Database\Eloquent\Model`
ya usa —`events`, `attributes`, `casts`, `table`, `connection`, `dates`,
`original`, `relations`, `visible`, `hidden`— y a partir de ahí el modelo
sobrescribe una pieza del framework sin decirlo. Lo detecta **Rector**
(`RenamePropertyRector`) y el aviso **no** es un falso positivo: si sale, la
columna se renombra.

Cicatriz: `subscribed_events` en `webhook_endpoints` (v2.4.0). El nombre natural
era `events`, y con él `$endpoint->events` habría chocado con la propiedad
`$events` que Eloquent renombró a `$dispatchesEvents`: el
`LaravelLevelSetList::UP_TO_LARAVEL_130` de `rector.php` trae esa regla y
reescribía cada lectura del atributo. El prefijo `subscribed_` cuesta once
caracteres y además dice mejor lo que guarda.

### R61 · Un catálogo de terceros no viaja en el repositorio
SEPOMEX, los catálogos del SAT, GeoNames: entran por un comando de importación
y la tabla nace vacía. En el repositorio va sólo la parte pequeña y estable —las
32 entidades federativas del seeder— y las muestras que necesiten los tests.
> Enforcement: `kore:arch:check` (`checkNoBulkDataFiles`: ningún archivo de más de 256 KB en `database/data/**`, `app/Modules/*/Database/data/**` ni `app/Modules/*/Tests/fixtures/**`) · `composer arch` · **Warning**
> Escape: `arch-accepted`

Relacionada: R29 · R53, con las que comparte la idea de que el esquema y los
datos son cosas distintas: aquí lo que no viaja son los datos, y lo que sí viaja
es el comando que los trae.

**Por qué.** Un catálogo commiteado cuesta tres veces. Engorda el clon **para
siempre** —git guarda cada versión, así que actualizarlo el año que viene suma
otros catorce megas y no sustituye a los de este—, caduca sin avisar (nadie
revisa si el archivo del repo sigue siendo el que publica el organismo) y
arrastra la licencia de quien lo publica hasta cada proyecto derivado, que ya no
puede decir con qué se distribuye. Un comando de importación resuelve las tres:
la fuente es la oficial, la fecha es la del día en que se corrió y el repositorio
no distribuye nada de nadie.

Lo que sí entra son los datos que **no** cambian y que sin ellos el sistema no
arranca: las 32 entidades federativas ocupan menos de dos kilobytes y llevan
décadas siendo las mismas.

**Por qué es un aviso y no un error.** Porque hay muestras legítimamente
grandes, y bloquear el build por un fixture convertiría la regla en una válvula
ceremonial repetida en cada proyecto derivado. 256 KB es holgado para lo que
estos directorios deben contener y estrecho para lo que no.

**Cicatriz.** Notarium llevaba `database/data/sepomex.csv`, **14 MB** en el
repositorio, clonado entero por cada persona y cada corrida de CI que tocara el
proyecto. El módulo Mx de la v2.4.0 hace lo contrario: `mx:sepomex:import`
recibe una ruta o una `--url`, las tablas `mx_states` y `mx_postal_codes` nacen
vacías y lo único que viaja en el repositorio es `MxStatesSeeder` con las 32
entidades y un `sepomex-sample.txt` de dos kilobytes para los tests.

### R56 · Los archivos no se borran desde la interfaz: se archivan
`App\Core\Contracts\FileStore::archive()` es lo que hace la papelera de una
pantalla: marca la versión como reemplazada y la deja donde está.
`FileStore::delete()` destruye el archivo. Hoy **nadie** lo llama en el
boilerplate: `files:cleanup` usa `FileDeleteAction` directamente y el borrado en
cascada al eliminar al dueño lo hace el observer de media-library. El `allowIn`
de `Console/` y `Listeners/` es para el derivado que sí lo necesite.
> Enforcement: disallowed-calls (`kore.r56`) · `composer analyse` · **Error** · + Pest arch (ningún componente Livewire que consuma `FileStore` llama a `delete()`) · `./vendor/bin/pest tests/Arch` · **Error**
> Escape: `arch-accepted`

Relacionada: R55 (la otra mitad del contrato de archivos) y R29, que es la misma
idea aplicada al esquema: lo que se puede deshacer y lo que no se separan a
propósito, y lo segundo se escribe.

**Por qué.** «Eliminar» en una interfaz casi nunca significa destruir. Significa
«quítamelo de delante», y quien lo pulsa da por hecho que hay vuelta atrás
porque en todas las demás pantallas la hay. Un archivo, en cambio, no tiene
papelera del sistema operativo detrás: si la Action llama a `delete()`, los bytes
se han ido y la copia de seguridad de anoche es la única opción.

Separar las dos operaciones en el contrato hace que la decisión se tome una vez
—al escribir el método— y no en cada pantalla que suba algo. Y deja la
destrucción donde se puede razonar sobre ella: un comando programado con
`--days` y un listener que reacciona a que el dueño ya no existe.

**Cicatriz.** Notarium, donde `archivar()` existía desde el principio y no por
elegancia: un documento de un expediente es el soporte de un acto notarial, y
«eliminar» nunca puede querer decir destruirlo. Lo que allí era una convención
del dominio —recordada por quien la escribió— aquí es la forma del contrato:
`archive()` y `delete()` son métodos distintos y sólo uno de los dos está a mano
de la capa de entrega.

### Nota · La instalación limpia es un test, no un procedimiento

`tests/Feature/CleanInstallTest.php` reproduce lo que hacen `composer setup` y
el entrypoint de Docker: `migrate:fresh --seed` con los seeders reales, sin
fakes. Comprueba tres cosas que ningún test de módulo ve porque todos parten de
una base ya sembrada:

1. que `DatabaseSeeder` deja un `admin@example.com` con el rol `admin` y **todos**
   los permisos registrados, y que `ModulesSeeder` siembra sus módulos, roles y
   permisos;
2. que sembrar dos veces es idempotente —`db:seed` se corre a mano en producción
   y a veces se repite—;
3. que `E2eSeeder` levanta sus cuatro cuentas sobre una base vacía, que es la
   precondición de toda la suite de Playwright.

Va aquí y no en §6 · Tests porque lo que verifica no es una convención de tests
sino una propiedad de los datos: el estado inicial del sistema. Un boilerplate
cuyo `db:seed` revienta la segunda vez no es reutilizable (lo hacía hasta la
v1.3.0), y eso no lo veía ninguna regla.

---

## §5 · UI e i18n

### R31 · Sólo componentes koreUi
`<x-kore::*>`. Nada de Flux UI ni de otras librerías.
> Enforcement: **Manual**
> Escape: `arch-accepted`

**Por qué.** Dos librerías de componentes significan dos sistemas de tokens, dos
temas oscuros y dos comportamientos de foco. El coste no es el bundle: es que
nadie sabe cuál usar.

**Cicatriz.** Sin cicatriz todavía.

### R32 · Verificar las props antes de escribirlas
Con `mcp__kore-ui__get-component-docs` (y `list-components` antes de crear algo
nuevo).
> Enforcement: **Manual**
> Escape: ninguna

**Por qué.** Una prop que no existe **no falla**: Blade la vuelca en el
attribute bag como atributo HTML suelto y el componente usa su valor por
defecto, en silencio.

**Cicatriz.** Las alertas de autenticación estuvieron pintadas de azul durante
semanas por un `color="destructive"`; la prop del componente es `type`.

### R57 · Las imágenes y el CSS de una hoja PDF van embebidos, nunca enlazados
En una plantilla que acaba convertida a PDF —las del módulo Pdf y las que un
módulo guarda bajo un `pdf/` dentro de sus vistas— el CSS va en un `<style>` en
línea y las imágenes como `data:` URI
(`App\Core\Support\PdfImage::embedded()`). Nada de `@vite`, de una hoja de
estilos enlazada, de `asset()` ni de un `src` que empiece por `http` o por `//`.
> Enforcement: `kore:arch:check` (`checkPdfSheetsAreSelfContained`) · `composer arch` · **Error**
> Escape: ninguna

Relacionada: R30, de la que ésta es la vecina —las dos dicen qué no puede hacer
una Blade—, sólo que R30 mira hacia dentro (consultas) y R57 hacia fuera
(recursos).

**Por qué.** Quien convierte la hoja es Gotenberg, y corre en **otro
contenedor**. Cuando la plantilla pide `http://127.0.0.1/build/app.css` se lo
pide a sí mismo, no a la aplicación. Y el fallo no revienta: el PDF se genera
igual, con el mismo peso y sin ningún error en los logs; lo único que pasa es
que sale sin maquetar, o con el icono de imagen rota donde iba el logo.

Peor todavía, el fallo depende del entorno. Con la dirección pública de
producción el enlace sí resolvería, así que la hoja rota en local funciona al
desplegar —o al revés—, y nadie sabe cuál de las dos es la buena. Embebido no
hay dos entornos: lo que se revisa en la vista previa del navegador es
exactamente lo que se imprime.

Y hay una tercera razón que vale también en producción: los archivos que suben
los usuarios viven en disco privado y se sirven por URL firmada y temporal
(R55). Embebida, la hoja no depende de que el convertidor alcance la aplicación
ni de que la firma siga viva cuando pase a buscarla.

**Cicatriz.** asper-server, y está escrita en el docblock de sus `PdfImagen` y
`PdfLogo`: el logo enlazado salía roto en el PDF y entero en la vista previa, así
que el problema parecía del PDF y era de la dirección. El boilerplate heredó las
dos clases como `App\Core\Support\PdfImage` y `App\Modules\Pdf\Support\PdfLogo`
en la v2.3.0, y la regla existe para que la siguiente hoja no vuelva a
descubrirlo.

### R33 · Español es el idioma fuente
Se escribe `__('Texto en español')`; la traducción al inglés va en el
`Resources/lang/en.json` del módulo (o en `lang/en.json` si es compartida). Si
el literal ya viene en inglés desde un paquete, su traducción va en
`lang/es.json`.
> Enforcement: `TranslationsTest` · `composer test` · **Error**
> Escape: ninguna

**Por qué.** Con una sola fuente, `APP_LOCALE=es` devuelve la clave tal cual y
no hace falta mantener dos catálogos sincronizados a mano.

**Cicatriz.** El boilerplate tenía 171 llamadas a `__()` con literales en
español y `APP_LOCALE=en`: la mitad de la interfaz caía al fallback y la otra
mitad no. Corregido en la v1.1.0.

### R34 · Sin interpolación dentro de `__()`
`__('Hola, :name', ['name' => $user->name])`, nunca `__("Hola, {$name}")`.
> Enforcement: `TranslationsTest` · `composer test` · **Error**
> Escape: ninguna

Relacionada: R33, de la que ésta es el modo de fallo silencioso. Las verifica el
mismo test.

**Por qué.** La clave de traducción se construye en runtime y por tanto nunca
existe en el JSON. El texto sale sin traducir y el extractor no lo ve.

**Cómo se verifica.** El extractor de `TranslationsTest` captura el literal tal y
como está escrito, `{$name}` incluido: la clave que extrae es
`"Hola, {$name}"`, que no está —ni puede estar— en ningún JSON. El test la
lista como «clave sin traducir» y falla. Comprobado metiendo una a propósito:
sale en el listado con su archivo y el comando devuelve exit 1.

**Cicatriz.** Sin cicatriz todavía.

---

## §6 · Tests

### R35 · Un test Pest por Action, componente Livewire y ruta
> Enforcement: **Manual** · **Error** en review
> Escape: ninguna

**Por qué.** Es la única forma de que las 52 reglas restantes signifiquen algo:
un arch test dice que la clase está en su sitio, no que haga lo que promete.

**Cicatriz.** `README.md` y `CLAUDE.md` prometían arch tests «desde el día uno»
y la auditoría encontró **cero** llamadas `arch()`. La lección no es sobre arch
tests: es sobre prometer verificación que no existe.

### R36 · Todo módulo con UI aporta smoke + happy path + autorización por rol
Como mínimo, en `tests/e2e/specs/{modulo}/`.
> Enforcement: **Manual** · **Error** en review · la suite: `npm run e2e`
> Escape: `arch-accepted`

**Por qué.** Hay fallos que sólo existen en el navegador: Livewire, Alpine y
koreUi cooperando.

**Cicatriz.** Borrar un usuario desde la acción de fila no hacía **nada**.
koreUi 2.2 arma el diálogo de `RowAction::confirm()` en el cliente pero no
registra el método en `$koreConfirmable`, así que el listener lo descartaba. Los
tests de Livewire pasaban en verde porque invocan el método directamente; sólo
el E2E lo vio.

### R37 · Sin `data-testid` en las Blade
Localizadores accesibles: `getByRole` → `getByLabel` → `getByPlaceholder` →
`getByText`. Si algo no se puede localizar, usa CSS estable y anótalo como
mejora de accesibilidad.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-exception`

**Por qué.** Un `data-testid` es una tirita sobre un problema de accesibilidad:
si el test no encuentra el botón por su rol y su nombre, un lector de pantalla
tampoco.

**Cicatriz.** Sin cicatriz todavía.

### R38 · Sin `waitForTimeout()` en los E2E
Se espera a un cambio observable: un toast, una fila, la URL, `toHaveCount`.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: `arch-exception`

**Por qué.** Un `sleep` es lento cuando la app va bien y flaky cuando va mal. Es
la causa número uno de suites E2E que nadie mira.

**Cicatriz.** Sin cicatriz todavía: los dos únicos `waitForTimeout` de
`tests/e2e/` están en comentarios que explican por qué no se usa
(`support/livewire.ts` y `support/mail-log.ts`). Los que aparecen en
`tests/Feature/ArchCheckCommandTest.php` son fixtures del propio check —código
que viola la regla a propósito para comprobar que el check lo pilla— y viven
fuera de `tests/e2e/`, que es lo único que R38 mira.

### R39 · Cada test E2E crea sus propios datos
Con `uniqueEmail()` / `uniqueName()`. La base sólo se resetea en `globalSetup`.
> Enforcement: **Manual** · **Error** en review
> Escape: ninguna

**Por qué.** Con datos compartidos, el orden de ejecución decide el resultado y
`--workers=4` lo decide al azar.

**Cicatriz.** Sin cicatriz todavía.

### R51 · El harness de pruebas sólo existe con flag, entorno y base de pruebas
Las rutas `/__e2e__/*` del módulo E2E se registran únicamente cuando se cumplen
las **tres** condiciones a la vez: `E2E_HARNESS=true`, un entorno de la lista
blanca (`e2e`, `testing`, `local`) y una conexión cuya base se llame como una
base de pruebas —contiene «e2e» o «test», o es `:memory:`—. Con cualquiera de
las tres cerrada, el provider hace `return` y no registra nada.
> Enforcement: tests (`app/Modules/E2E/Tests/Feature/HarnessGuardTest.php`, `HarnessRoutesTest.php`) · `composer test` · **Error**
> Escape: ninguna

Relacionada: R10, de la que ésta es el caso extremo. R10 dice que un toggle
apagado no registra nada; R51 dice que, para este toggle, **encendido tampoco
basta**.

**Por qué.** El harness crea usuarios con el rol que le pidan, se salta el
formulario de login y corre comandos de artisan, todo sin autenticación. Es
exactamente lo que una suite E2E necesita y exactamente lo que no puede existir
en un servidor. Un solo flag no da esa garantía: un `.env` se copia de una
máquina a otra, y el error no avisa —la aplicación funciona perfectamente, sólo
que con una puerta abierta—.

El tercer candado es el que de verdad protege, y por eso no es opcional. Un flag
se enciende por equivocación y un entorno se puede llamar `local` en una máquina
que no lo es; lo que no pasa por accidente es que la base de producción se llame
`algo_e2e`. Mientras la conexión apunte a la base real, el harness sigue muerto
aunque los otros dos candados estén abiertos.

Por lo mismo la lista blanca de entornos es una constante de `HarnessGuard` y no
una clave de `config/kore-app.php`: un candado que se puede ampliar desde el
`.env` deja de serlo. Y por lo mismo `scripts/e2e-seed.sh` repite la
comprobación en bash antes de llamar a artisan: `migrate:fresh` borra la base
**antes** de que ningún PHP tenga ocasión de opinar.

**Cicatriz.** Heredada, no propia. El harness viene de **asper**, el primer
proyecto derivado que necesitó montar escenarios sin recorrer doce pantallas.
Allí los tres candados no se escribieron de golpe: el primero fue el del
**seeder**, que se niega a correr si el nombre de la base no contiene «e2e» ni
«test», porque `migrate:fresh --seed` apuntando al `.env` equivocado no avisa,
no pregunta y no se deshace. Cuando llegó el harness —que además de sembrar
**abre sesiones**— se reusó exactamente esa comprobación (`HarnessGuard::
isTestDatabase()` la comparten el provider y el seeder) y se le añadieron el
flag y el entorno por delante. La lección que sube al boilerplate es la del
orden: el candado que se escribió primero fue el que miraba la base, no el que
miraba el flag.

### R52 · Toda pantalla nueva entra en `access-map.ts`
Cada ruta **GET con nombre** de un `Routes/web.php` que sirva una pantalla
tiene su entrada en `tests/e2e/fixtures/access-map.ts`, con su `path` literal,
su `nombre`, su `heading` y el resultado esperado para los cinco perfiles.
> Enforcement: `kore:arch:check` (compara las rutas GET con nombre de los `Routes/web.php` contra los `path:` del mapa) · `composer arch` · **Error**
> Escape: `arch-accepted` (ver §Válvulas)

Relacionada: R36, de la que ésta es la mitad verificable. R36 pide «smoke +
happy path + autorización» y no hay forma de comprobar que existan; R52 pide
una línea en un archivo, y esa línea **genera** el smoke y la matriz de
autorización. Lo que R36 sigue pidiendo a mano es el happy path.

**Por qué.** Una pantalla nueva sin permiso —o con el permiso equivocado— no se
nota en ninguna pantalla. No hay error, no hay excepción, no hay log: hay
alguien viendo algo que no le tocaba, y nadie mirando. La única forma de que
salte es que alguien recorra la matriz completa de roles × rutas, y eso nadie lo
hace a mano dos veces.

La regla existe porque el coste de cumplirla es una entrada de siete líneas y
el beneficio son cinco tests de autorización y uno de smoke que aparecen
solos. Cuando cubrir una pantalla cuesta menos que no cubrirla, se cubre.

El `path` tiene que ser un **literal entre comillas simples**, absoluto y sin
parámetros: el check lee el archivo como texto, así que un `path` construido
(`` `/users/${id}/edit` ``) o sacado de una constante sería invisible. Las rutas
con parámetro quedan fuera del mapa a propósito y se cubren con su propio spec,
llegando como se llega en la aplicación real.

**Cómo se verifica.** El check lee el **texto** de `routes/web.php` y de
`app/Modules/*/Routes/web.php` —componiendo los `->prefix()` de los grupos— y
no `Route::getRoutes()`, para poder correr en un pre-commit y sobre un árbol de
fixtures sin bootear la aplicación ni depender de qué toggles tenga encendidos
la máquina. Si el mapa todavía no existe (un derivado sin suite E2E, o el commit
que la introduce), avisa una vez y no falla: R52 exige que la pantalla esté en
el mapa, no que el proyecto tenga E2E. La válvula es **de línea**, no de
archivo: un `web.php` declara varias pantallas y eximir a una no puede tapar a
las demás.

**Cicatriz.** La de asper, que es el proyecto donde nació el mapa. Allí la
matriz `02-rbac` es «el spec más aburrido de leer y el más útil de tener», y en
sus 27 pantallas × 7 roles destapó dos cosas que ninguna otra capa vio:

- **ASPER-E2E-006** — un trabajador social podía abrir el estudio de un
  compañero, de cualquier empresa cliente: domicilio, composición familiar,
  ingresos y fotografías del interior de la casa de candidatos que no le
  tocaban. `roles.md` decía desde el primer día «studies.view: TRABAJADOR SOCIAL
  ✅ (asignados)»; la implementación daba paso libre a todo el personal interno.
  La documentación y el código llevaban meses diciendo cosas distintas y nadie
  tenía dónde verlo.
- **ASPER-E2E-001** — el permiso `studies.create` prometía una pantalla que
  respondía 403, porque `mount()` aborta a quien no tenga `client_id`. Un
  desajuste entre lo que el catálogo de permisos promete y lo que la pantalla
  cumple, que el mapa fija con `soloRoles` en vez de dejarlo como sorpresa.

En kore-laravel el mapa nació con la v2.1.0 y ya destapó dos asimetrías el
primer día: `/magic-link` no rebota a quien tiene sesión, a diferencia de las
otras cuatro pantallas de acceso (KORE-E2E-002), y `/pulse` es la única ruta que
responde 403 a un invitado en vez de mandarlo al login (KORE-E2E-003). Ninguna
de las dos es un agujero; las dos llevaban ahí desde siempre y nadie las había
visto.

### R62 · Un recorrido del manual toma los localizadores de `tests/e2e/pages/`
En `tests/e2e/manual/*.guia.ts` no se localiza a mano: nada de
`page.locator('.clase')`, `page.locator('#id')` ni `page.$()`. Lo que la guía
necesita señalar sale del page object de esa pantalla, y si no está, se añade
allí.
> Enforcement: `kore:arch:check` (`checkManualGuidesUsePageObjects`) · `composer arch` · **Warning**
> Escape: `arch-accepted`

Relacionada: R37, que es la misma idea un piso más abajo —los specs localizan por
rol, etiqueta o texto, y sólo bajan a CSS estable cuando no hay alternativa
accesible—. R62 no es más estricta: un `page.locator('table tbody tr')` no entra,
porque no ata nada a la hoja de estilos.

**Por qué.** El manual es la única capa de la suite que **no corre en CI**: se
genera a mano con `npm run manual`. Un recorrido que busca por su cuenta se rompe
en silencio el día que cambia una clase —el spec de esa pantalla sigue verde,
porque él localiza por rol— y nadie se entera hasta que alguien abre el PDF y ve
una captura de otra cosa, o un capítulo que murió en un `timeout`.

Compartir el page object invierte eso: arreglar la pantalla arregla el spec **y**
el manual en el mismo commit, y el manual deja de ser documentación que hay que
mantener aparte para ser una salida más de la suite.

**Por qué es un aviso y no un error.** Por lo mismo que R61: hay pantallas
—tablas de terceros, widgets sin rol— donde el CSS es lo único que hay, y un
error obligaría a una válvula por cada una. El aviso se lee y se decide.

**Cicatriz.** Las guías de asper, escritas antes que sus page objects, con los
selectores copiados de la consola del navegador. En kore-laravel la regla llegó
a tiempo: el recorrido `01-usuarios.guia.ts` de la v2.4.0 empezó recortando la
captura con un `page.locator('table')` escrito en la propia guía y se corrigió en
el mismo ciclo (`refactor(e2e): la tabla del listado de usuarios, en su page
object`) moviéndolo a `UsersIndexPage`, que es el único sitio que habrá que tocar
si el listado deja de pintar un `<table>`.

---

## §7 · Documentación, versionado y excepciones

### R40 · La documentación es parte del entregable
Un cambio de comportamiento actualiza su doc en el mismo commit; todo
`docs/**/*.md` está enlazado desde `docs/README.md`; y toda `R{n}` citada desde
el código existe en este archivo.
> Enforcement: `kore:arch:check` (índice + citas) · `composer arch` · **Error** · «mismo commit»: **Manual**
> Escape: ninguna

**Por qué.** Un doc que miente es peor que un doc que falta: el que falta se
busca, el que miente se cree. Y una regla citada que no existe convierte el
número en ruido.

**Cicatriz.** `UserForm` citaba `docs/guides/crud/livewire-form.md`, que no
existe. `ModulesSeeder` afirmaba que el `Gate::before` del superadmin está en
`AppServiceProvider`, cuando está en `AuthModuleServiceProvider`. Ambos
corregidos en la v1.0.0.

**Nota · el check recorre el disco, no el índice de git.** `checkDocs` lista
`docs/**/*.md` con el sistema de archivos, así que un `.md` **generado** y
listado en `.gitignore` también tiene que aparecer en `docs/README.md`: si no,
`composer arch` falla en la máquina de quien lo generó y pasa en CI, que es el
peor reparto posible. Es el caso del manual de usuario de la v2.4.0
(`npm run manual` escribe `docs/manual/NN-slug.md`, ignorados salvo el
`README.md` del directorio): el índice enlaza `docs/manual/README.md`, que es el
que sí viaja, y desde ahí se llega a lo generado. La alternativa —enseñarle al
check a leer `.gitignore`— haría que un doc de verdad desapareciera del índice el
día que alguien añadiera un patrón demasiado ancho.

### R41 · Toda cifra de un doc se puede verificar
Y se actualiza en el commit que la cambia. Si no se puede verificar
automáticamente, no se escribe.
> Enforcement: **Manual** · **Warning**
> Escape: ninguna

Relacionada: R40, de la que ésta es un corolario. R40 verifica que el doc
*existe* y que lo que cita *existe*; R41 se ocupa de lo que el doc *afirma*, que
es justo la parte que ninguna herramienta puede comprobar.

**Por qué.** Contar los tests exige correr la suite, así que aquí no hay
verificador barato; lo que sí hay es la regla de que un número inventado es peor
que ningún número.

**Cicatriz.** `pipeline.md` decía «15 tests» cuando había 32, y el landing
anunciaba «32 Tests Pest» **hardcodeado** en la Blade. Los dos, v1.0.0.

### R42 · Toda release tiene su entrada en el CHANGELOG
Formato Keep a Changelog, con nota de migración para proyectos derivados. El tag
`vX.Y.Z` no se publica si `CHANGELOG.md` no tiene su sección `## [X.Y.Z]`.
> Enforcement: `kore:changelog:section` desde `.github/workflows/release.yml` · `git push --tags` · **Error** · que la nota de migración sirva de verdad: **Manual** · **Error** en review de release
> Escape: ninguna

Relacionada: R40, de la que ésta es el corolario en el eje del tiempo. R40 pide
que el doc esté al día **hoy**; R42 pide que quede escrito **qué cambió** para
quien viene de la versión anterior.

**Por qué.** El CHANGELOG es la única API de actualización que tiene un
proyecto derivado: es lo que lee para saber qué aplicar de `git merge v1.x`.

**Cicatriz.** El repositorio llegó a la auditoría con **0 tags**, sin
`CHANGELOG.md` y sin ninguna forma de actualizar un derivado. Era el bloqueante
estructural del proyecto.

Y una segunda, de la propia regla: hasta la v1.3.0 el único verificador era el
review de la release, es decir, acordarse. Desde la v1.4.0 el workflow de
release le pide la sección al `CHANGELOG.md` con
`php artisan kore:changelog:section vX.Y.Z` y, si no está, **no publica el
release**: el tag se queda sin página y el derivado sin nota de migración, que
es exactamente el fallo que la regla existe para evitar. El generador automático
—release-please y familia— se descartó a propósito: reescribiría en inglés,
desde los subjects de los commits, un archivo que aquí se escribe a mano y en
español porque es la API de actualización de los proyectos hijos.

### R43 · Conventional commits
`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`, `perf:`, `ci:`,
`build:`, `style:` y `revert:`, con ámbito opcional (`feat(users):`) y `!` para
breaking changes. Los mensajes que escribe git por su cuenta —`Merge …`,
`Revert "…"`, `fixup!` / `squash!` / `amend!`— pasan sin tocar.
> Enforcement: hook `commit-msg` (`App\Core\Console\Hooks\ConventionalCommitMsgHook`) · `git commit` · **Error** · que el tipo elegido sea el correcto: **Manual**
> Escape: ninguna

**Por qué.** Es lo que permite generar el CHANGELOG y calcular el siguiente
semver sin discutirlo.

**Cicatriz.** Sin cicatriz todavía. Hasta la v1.3.0 esta regla era la más fácil
de romper del catálogo —era **Manual** y **Warning**, y el propio
`pipeline.md` la listaba en «cómo subir el listón»—, así que en la v1.4.0 se le
puso el hook. Se valida sólo la **primera línea útil** del mensaje, que es la
que acaba en el `git log --oneline`; el cuerpo y el pie (`Co-Authored-By`,
`Refs`) no se miran.

### R44 · Una excepción de arquitectura nunca la aprueba el agente
Las válvulas tienen gramática fija (§Válvulas), citan una regla existente,
llevan `@owner` y —si son temporales— fecha de caducidad. **Claude, Codex o
cualquier otro agente que necesite una válvula se detiene y pregunta**: el
dueño lo pone una persona.
> Enforcement: `kore:arch:check` (gramática, regla existente, `@owner`, caducidad) · `composer arch` · **Error** · quién la aprueba: **Manual**
> Escape: no aplica

**Por qué.** Una excepción sin dueño y sin fecha no es una excepción: es la
regla nueva. Y un agente que se autoriza sus propias excepciones convierte
cualquier regla en una sugerencia.

**Cicatriz.** Los arch tests de imports cruzados estuvieron **comentados** con
un `TODO v1.1` al lado: una excepción sin dueño, sin fecha y sin revisión que
duró una release entera. Se activaron en la v1.1.0.

### R45 · Todo baseline lleva fecha de caducidad
Si existe `phpstan-baseline.neon`, su primera línea es
`# arch-baseline: vence YYYY-MM-DD` y la fecha no ha pasado.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: renovar la fecha es la excepción, y se hace con el equipo

Relacionada: R44. Un baseline es la tercera forma de válvula —después del
comentario y del `@phpstan-ignore`—, sólo que exime miles de líneas de golpe y
sin decir de quién es. Por eso lo único que se le exige es lo mismo que a una
`arch-exception`: una fecha, y que no haya pasado.

**Por qué.** Un baseline es deuda con la palabra «temporal» delante. Sin fecha,
lo temporal es el proyecto.

**Cicatriz.** Sin cicatriz todavía: hoy no hay baseline y el objetivo es que
siga sin haberlo.

### R49 · Los skills viven en `.agents/skills/` y `.claude/skills/` son enlaces
La carpeta real de cada skill es `.agents/skills/{nombre}/`. En
`.claude/skills/{nombre}` va un **symlink relativo** a
`../../.agents/skills/{nombre}` —uno por skill, no uno de la carpeta padre— y
nada más: ni copias, ni carpetas sueltas, ni enlaces con ruta absoluta.
> Enforcement: `kore:arch:check` · `composer arch` · **Error**
> Escape: ninguna

**Por qué.** Los dos clientes leen la misma carpeta de formas distintas: Claude
Code sigue los symlinks **a nivel de skill individual** (no los de la carpeta
padre, por eso los enlaces son nueve y no uno), y Codex no resuelve enlaces en
absoluto. De ahí el reparto: la carpeta real es la del estándar Agent Skills
—`.agents/skills/`, la que lee el cliente que no sigue enlaces— y el espejo de
Claude Code se resuelve solo. El enlace es relativo porque uno absoluto rompe el
repositorio en cualquier otro clon, y Git versiona symlinks (modo `120000`), así
que la estructura viaja en el commit y no hay nada que reinstalar.

**Cicatriz.** Hasta la v1.3.0 los ocho skills estaban **duplicados byte a byte**
en las dos carpetas y `working-with-ai.md` documentaba el mantenimiento a mano:
`cp -R .claude/skills/mi-skill .agents/skills/` y `diff -r` para comprobarlo.
Que las copias no se hubieran desincronizado todavía era cuestión de tiempo, y
además R40 barría los dos sets: cada cita de regla de un skill se leía —y se
podía reportar— dos veces.

### R50 · `AGENTS.md` se genera desde `CLAUDE.md`
`AGENTS.md` no se edita: es un artefacto —cabecera de aviso más `CLAUDE.md`
íntegro— que produce `php artisan kore:agents:sync`. Lo que se edita es
`CLAUDE.md`, y el commit lleva los dos.
> Enforcement: `kore:arch:check` (mismo contenido esperado que `kore:agents:sync --check`) · `composer arch` · **Error**
> Escape: ninguna

Relacionada: R40, de la que ésta es el caso extremo —un doc que miente porque
alguien editó su gemelo— y, a diferencia de R40, con el arreglo en un comando.

**Por qué.** Dos archivos que tienen que quedar idénticos y se mantienen a mano
se desincronizan el día que alguien edita el que tenía abierto; y el que se
queda viejo es el que lee **el otro** agente, así que el error no lo ve quien lo
cometió. Generar uno desde el otro convierte la disciplina en un comando, y el
check convierte el comando en un fallo del build. El hook de pre-commit **no**
lo regenera a propósito: un hook que escribe deja commiteado algo distinto de lo
que se revisó.

**Cicatriz.** Los dos archivos existían idénticos desde la v1.0.0 y el único
verificador era un `diff CLAUDE.md AGENTS.md` que había que acordarse de correr;
`working-with-ai.md` lo pedía con la frase «se escribe uno y se copia». La
v1.3.0 los tocó a los dos en cinco sitios distintos (el rango `R1..R48`, las
ocho claves de `kore-app`, las reglas de oro…) y sobrevivieron de milagro.

---

## Válvulas de escape

Dos formas, y sólo dos. Se escriben en un comentario del lenguaje que toque
(PHP, TypeScript, Blade), pegadas al código que las necesita:

```php
// arch-exception: R12 · razón breve · @owner · 2026-12-31
// arch-accepted:  R20 · razón breve · @owner
```

| Forma | Cuándo | Caducidad |
|-------|--------|-----------|
| `arch-exception` | Deuda reconocida que se va a pagar. | **Obligatoria.** Cuando vence, `composer arch` falla. |
| `arch-accepted` | Decisión revisada y aceptada: aquí la regla no aplica. | Ninguna. Se revisa cuando cambie el contexto. |

Reglas de la gramática, verificadas por `kore:arch:check --rule=R44`:

1. Los separadores son ` · ` (punto medio). Ni comas ni guiones.
2. La regla citada (`R12`) tiene que existir en este archivo.
3. `@owner` es obligatorio y es **una persona**, no un equipo ni un agente.
4. En `arch-exception`, la fecha es `YYYY-MM-DD` y va la última.
5. **La forma tiene que ser una de las que la regla declara en su `> Escape:`.**
   Si dice `arch-accepted`, una `arch-exception` sobre esa regla es un error, y
   al revés; si dice «ninguna», cualquier válvula lo es.

El punto 5 existe porque las dos formas no son sinónimos con distinta sintaxis.
`arch-exception` es deuda: alguien la va a pagar, y la fecha dice cuándo.
`arch-accepted` es una decisión: aquí la regla no aplica y no hay nada que
arreglar. Usar la que no toca rompe las dos mitades del sistema —una deuda
etiquetada como decisión no caduca nunca, y una decisión etiquetada como deuda
caduca sin que nadie tenga nada que hacer con ella— y por eso lo verifica el
comando en vez de dejarlo al criterio de quien la escribe.

Una regla que no declara `> Escape:` no restringe nada: el check no se inventa
límites que el catálogo no pone. Eso mantiene utilizable un catálogo adaptado
por un proyecto derivado.

### Lo que las herramientas de PHPStan no entienden

PHPat y disallowed-calls no leen estos comentarios. Para ellos hay dos vías, en
este orden:

1. **La preferida — lista blanca por ruta**, en `phpstan-disallowed.neon`
   (`allowIn` / `allowExceptIn`). Deja la excepción en un único sitio revisable
   junto al resto, y no se propaga.
2. **Puntual — `@phpstan-ignore` con el mismo texto de la válvula al lado**,
   para que el motivo y el dueño viajen con la línea:

   ```php
   /** @phpstan-ignore-next-line arch-exception: R21 · migración de datos legacy · @cesar · 2026-12-31 */
   DB::table('legacy_users')->update(['migrated' => true]);
   ```

   `kore:arch:check --rule=R44` sí lee esa línea, así que la caducidad se sigue
   verificando aunque PHPStan la ignore.

### Y la regla que las gobierna

**El agente no se aprueba excepciones a sí mismo (R44).** Si Claude o Codex
llega a un punto donde hace falta una válvula, para y pregunta. El `@owner` lo
escribe una persona, porque el `@owner` es quien responde cuando la fecha vence.

---

## Capas de verificación

Cada capa tiene un presupuesto de tiempo. Si una capa se pasa, se mueve trabajo
a la siguiente, no se aguanta: un pre-commit de diez segundos se acaba saltando
con `--no-verify`, y entonces no verifica nada.

| Capa | Presupuesto | Medido | Qué corre |
|------|-------------|--------|-----------|
| **pre-commit** | ~2 s | **1,1 s** | `pint --dirty` + `kore:arch:check --files=<staged>` |
| **commit-msg** | ~1 s | **0,3 s** | `ConventionalCommitMsgHook` — el asunto sigue Conventional Commits (R43) |
| **pre-push** | ~30 s | **10 s** | `phpstan` (Larastan + PHPat + disallowed-calls) + `pest --parallel` |
| **`composer ci`** | ~90 s | **43 s** | `pint --test` (0,5 con caché) + `phpstan` (1,3 con caché) + `composer arch` (0,4) + `rector --dry-run` (3,7 con caché) + `pest` (36,9, secuencial) |
| **CI (GitHub)** | ~3 min | — | `composer ci` en matriz PHP 8.4 / 8.5 + `composer audit` + `npm ci && npm run build` + E2E (256 tests en 27 archivos) |
| **Release (GitHub)** | — | — | sólo al empujar un tag `v*`: `kore:changelog:section` + GitHub Release (R42) |

Medido en un MacBook (Apple Silicon, PHP 8.4) sobre el repositorio a fecha de
la v2.4.0, con 1245 tests Pest. La suite E2E —256 tests en 27 archivos— va
aparte y no entra en ninguna de estas capas. Las cuatro primeras caben
holgadamente en su presupuesto: el margen es para que un proyecto derivado pueda
crecer sin tener que rediseñar el pipeline.

El `commit-msg` es la capa más barata del pipeline —una expresión regular sobre
una línea— y casi todo su tiempo medido es el arranque de `artisan`. Va aparte y
no dentro del pre-commit porque git se lo pasa en otro momento: el pre-commit
mira el contenido y todavía no hay mensaje que mirar.

Los hooks se instalan solos (`composer install` dispara `git-hooks:register`) y
se re-registran a mano con `php artisan git-hooks:register`.

---

## Índice por herramienta

| Herramienta | Comando | Reglas |
|-------------|---------|--------|
| **Pest arch** (`tests/Arch/ArchitectureTest.php`) | `./vendor/bin/pest tests/Arch` | R1, R2, R3, R5, R6, R7, R8, R9, R13, R14, R17, R18, R25, R54, R56 |
| **PHPat** (`tests/Arch/PhpatArchitecture.php`) | `composer analyse` | R1, R4, R5, R6, R7, R8, R19 |
| **disallowed-calls** (`phpstan-disallowed.neon`) | `composer analyse` | R17, R18, R19, R20, R21, R22, R27, R56 |
| **Larastan nivel 8** | `composer analyse` | R15 |
| **`kore:arch:check`** | `composer arch` | R11, R23, R24, R29, R30, R37, R38, R40, R44, R45, R49, R50, R52, R55, R57, R58, R59, R60, R61, R62 |
| **Pint** | `composer lint` | R13, R16 |
| **Tests Pest** | `composer test` | R10, R12, R26, R27, R28, R29, R33, R34, R46, R47, R48, R51, R54 |
| **Hooks de git** (`config/git-hooks.php`) | `git commit` | R43 |
| **GitHub Actions** (`.github/workflows/release.yml`) | `git push --tags` | R42 |
| **E2E Playwright** | `npm run e2e` | *ninguna* — ver abajo |
| **Revisión humana** | code review | R2, R4, R9, R10, R12, R14, R16, R25, R28, R31, R32, R35, R36, R39, R40, R41, R42, R43, R44, R48, R53 |

**Por qué la fila de `npm run e2e` está vacía.** Es la única, y es a propósito.
La suite E2E es el **objeto** de R36, no su verificador: correrla comprueba que
los specs que ya existen pasan, no que un módulo nuevo con UI haya aportado los
suyos. Que estén el smoke, el happy path y el de autorización por rol sólo lo ve
una persona en el review, y por eso R36 cuenta como manual. Las dos reglas que
sí se pueden verificar sobre el código de los E2E —R37 (`data-testid`) y R38
(`waitForTimeout`)— las comprueba `composer arch` leyendo los `.ts`, sin
arrancar un navegador.

Varias reglas aparecen en más de una fila: casi ninguna se verifica entera con
una sola herramienta. R29 es desde la v1.3.0 el ejemplo más claro de por qué una
regla necesita dos verificadores: `kore:arch:check` lee el archivo y ve el
`down()`, y `MigrationsAreReversibleTest` lo ejecuta. Lo primero es gramática;
lo segundo, semántica. R2, por ejemplo, tiene el sufijo `Action` vigilado por
Pest arch y la semántica del nombre (`{Domain}{Object}{Verb}`) en revisión
humana; R14 tiene el `final` verificado en Actions, Data, Events, Rules,
Policies y Providers, y a ojo en el resto.

De las 62 reglas, **55 tienen al menos un verificador automático** y **7 son
íntegramente manuales**: R31, R32, R35, R36, R39, R41 y R53. Ninguna regla está
sin clasificar: si dice **Manual**, es porque hoy no hay forma barata de
verificarla, no porque nadie lo haya mirado.

Una aclaración que la v2.4.0 hizo necesaria: «verificador automático» no es lo
mismo que «falla el build». R61 y R62 son **Warning**, así que
`kore:arch:check` las imprime en amarillo y devuelve 0. Están en la primera
columna porque las verifica una herramienta y no un par de ojos, que es lo que
separa las dos listas; su severidad dice otra cosa —si bloquean o no—, y esa
está en su entrada.

Las cinco de la v2.4.0 son las cinco checks textuales de `kore:arch:check`, tres
de ellas **Error** y dos **Warning**: **R58** (ningún API Resource declara un
campo `data`) y **R60** (el catálogo de autorización no lee toggles) leen dos
listas de archivos muy concretas; **R59** (el middleware va en un solo array) es
la única que no se puede hacer con una expresión regular —hace falta partir el
archivo en sentencias con `token_get_all()`—; y **R61** (un catálogo de terceros
no viaja en el repositorio) y **R62** (el manual toma los localizadores de los
page objects) avisan sin bloquear, porque las dos tienen casos legítimos y un
error las convertiría en una válvula ceremonial. Lo que ninguna de las cinco ve
es si el campo que sustituye a `data` está bien elegido, ni si el permiso que se
siembra siempre es el correcto; eso sigue siendo review.

Las tres de la v2.3.0 entran las tres en la primera columna, y con tres
verificadores distintos: **R55** (las URL de archivo salen de `FileStore`) y
**R57** (las hojas de PDF no enlazan nada) son checks textuales de
`kore:arch:check`, y **R56** (archivar no es borrar) la vigila
disallowed-calls sobre `FileStore::delete()` más un arch test que barre los
componentes Livewire que consumen el contrato. Lo que ninguno de los tres ve es
si la policy que hay detrás de la URL firmada dice lo que tiene que decir; eso
sigue siendo review.

La de la v2.2.0 entra en la primera columna: **R54** la verifican los tres
`toExtend` de `tests/Arch/ArchitectureTest.php` —tolerantes a que un namespace
esté vacío, porque hoy sólo hay un endpoint— y `ApiExceptionRendererTest`, con
un caso por código canónico. Lo que ninguna de las dos ve es si el `code` que
eligió un endpoint es el correcto; eso sigue siendo review.

Las tres de la v2.1.0 se reparten 2/1: **R51** la verifica `HarnessGuardTest` y
**R52** el `kore:arch:check`, mientras que **R53** se queda manual —con su
skill detrás— porque el único que sabe qué atributos tenía una columna antes de
un `->change()` es el esquema real, y comprobarlo es un paso del proceso, no un
lint. La regla lo dice en su propia entrada, para que nadie vuelva a intentarlo
sin leer por qué se descartó.

El reparto anterior era 44/6 y no 42/8 porque **R42** y **R43** dejaron de ser manuales en
la v1.4.0: el hook `commit-msg` rechaza un asunto que no siga Conventional
Commits, y `release.yml` se niega a publicar un tag cuyo `CHANGELOG.md` no tenga
sección. Las dos siguen apareciendo en la fila de revisión humana porque su
mitad blanda —que el tipo del commit sea el correcto, que la nota de migración
le sirva de verdad a un derivado— no la ve ninguna herramienta; eso no las hace
manuales, igual que no lo son R2 ni R14.

Antes de eso, el reparto era 40/8 y no 39/9 porque **R34** (sin interpolación
dentro de `__()`) resultó estar verificada sin que nadie lo hubiera comprobado:
el extractor de `TranslationsTest` captura el literal tal cual, así que una clave
interpolada aparece como «sin traducir» y el test falla. Las dos veces, la misma
lección —la de R41— aplicada a este mismo archivo: la cifra se recuenta, no se
hereda.
