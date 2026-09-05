# Módulo E2E — el harness de la suite

**TL;DR**: `app/Modules/E2E/` publica un puñado de rutas `/__e2e__/*` con las que
la suite de Playwright crea usuarios, entra como un rol y lee el buzón de correo
sin recorrer cinco pantallas para llegar a la sexta. Es un backdoor deliberado,
y por eso vive detrás de **tres candados simultáneos**: el flag `E2E_HARNESS`, un
entorno de la lista blanca y una base de datos de pruebas. En producción el
módulo no registra ni una ruta.

## Por qué existe

Un test E2E honesto prueba por la UI **lo que está probando**. El problema es el
atrezzo: para comprobar que un `viewer` no ve el botón de borrar hay que tener un
viewer, y fabricarlo por pantalla significa entrar como admin, abrir el
formulario, crear la cuenta, asignarle el permiso, cerrar sesión y volver a
entrar. Seis pasos ya probados en otro spec, repetidos en cada test que necesite
un viewer.

El harness los sustituye por una llamada. Lo que el test quiere probar sigue
haciéndose por el navegador; lo que ya está probado se prepara por la puerta de
servicio.

## Los tres candados

Los aplica `App\Modules\E2E\Support\HarnessGuard` y hacen falta **los tres**:

| # | Candado | Cómo se comprueba |
|---|---------|-------------------|
| 1 | El flag | `config('kore-app.e2e.harness')`, que sale de `E2E_HARNESS` |
| 2 | El entorno | `app()->environment(['e2e', 'testing', 'local'])` |
| 3 | La base | el nombre de la base de la conexión por defecto contiene «e2e» o «test», o es `:memory:` |

**El tercero es el que de verdad protege.** Un flag se enciende por error en el
`.env` de un servidor —copiar y pegar es barato— y un entorno se puede llamar
`local` en una máquina que no lo es. Lo que no pasa por accidente es que la base
de producción se llame `algo_e2e`. Mientras la conexión apunte a la base real, el
harness sigue muerto aunque los otros dos candados estén abiertos.

`:memory:` cuenta como base de pruebas porque lo es por definición: vive en el
proceso y muere con él. Es la que usa `phpunit.xml`, y sin esa rama los tests de
Pest no podrían ejercitar el harness encendido.

La lista blanca de entornos es una **constante** de `HarnessGuard`, no una clave
de config: un candado que se puede ampliar desde el `.env` no es un candado.

`HarnessGuard::reasons()` devuelve, en español, qué candado falla. Es lo que
convierte «el harness no responde» en «la base se llama kore_prod».

## Estructura

```
app/Modules/E2E/
├── Http/Controllers/HarnessController.php   # los endpoints
├── Providers/E2EModuleServiceProvider.php   # boot() con return temprano (R10)
├── Routes/web.php                           # /__e2e__/*, sin auth y sin CSRF
├── Support/
│   ├── HarnessGuard.php                     # los tres candados
│   └── MailLog.php                          # el buzón
└── Tests/Feature/
    ├── HarnessGuardTest.php
    └── HarnessRoutesTest.php
```

No hay `Actions/`: el harness es infraestructura de pruebas, no casos de uso del
producto. Una `E2EUserCreateAction` sería una Action que la aplicación no llama
nunca y que duplicaría lo que ya hace `Users` — y no puede llamar a `Users`,
porque R5 prohíbe los imports cruzados. Trabaja sobre `App\Models\User` (que es
global) y `App\Core\Enums\SystemRole`, que es lo que R5 sí permite.

Tampoco hay `register()` en el provider: el controlador no necesita binding.

## Las rutas

Todas bajo el grupo `web` —varias necesitan sesión—, **sin `auth`** —su razón de
ser es preparar el terreno antes de que haya sesión— y **sin CSRF**. El cliente
es `fetch` desde Node: no tiene cookie previa ni token que enviar, y exigirlo
significaría pedirle a la suite que abra una página del producto sólo para
robarle el token.

> En Laravel 13 el middleware del grupo `web` que comprueba el token es
> `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`.
> `ValidateCsrfToken` es su **subclase deprecada**, y excluir ésa no quitaría
> nada: `Router::resolveMiddleware()` casa por clase exacta o por subclase, y la
> que corre es la padre.

El prefijo `__e2e__` es feo a propósito: nadie lo confunde con una ruta del
producto y salta a la vista en un `route:list`.

| Método | URI | Nombre | Entrada | Respuesta |
|--------|-----|--------|---------|-----------|
| `GET` | `/__e2e__/ping` | `e2e.ping` | — | `200 {ok, app, environment, database, users}` |
| `POST` | `/__e2e__/login-as` | `e2e.login-as` | `{email}` | `200 {id, email, roles[]}` · `404 {error}` |
| `POST` | `/__e2e__/logout` | `e2e.logout` | — | `200 {ok: true}` |
| `POST` | `/__e2e__/users` | `e2e.users.store` | `{role, email?, name?, password?, permissions?}` | `201 {id, email, roles[], permissions[]}` · `422 {error, allowed[]}` |
| `DELETE` | `/__e2e__/users` | `e2e.users.destroy` | `{email}` | `200 {deleted}` |
| `GET` | `/__e2e__/mail/last` | `e2e.mail.last` | `?to=` | `200 {to, subject, body, otp, links[]}` · `404 {error}` |
| `DELETE` | `/__e2e__/mail` | `e2e.mail.clear` | — | `200 {ok: true}` |
| `POST` | `/__e2e__/notify` | `e2e.notify` | `{email, title?, body?, category?, url?}` | `201 {ok, user_id, unread}` · `404 {error}` · `409 {error}` |
| `POST` | `/__e2e__/artisan` | `e2e.artisan` | `{command, arguments?}` | `200 {exit_code, output}` · `422 {error, allowed[]}` |
| `POST` | `/__e2e__/throttle/clear` | `e2e.throttle.clear` | `{keys?}` | `200 {ok: true}` |

Detalles que son contrato:

- **`users` (POST)** es `updateOrCreate` por email, así que un test puede
  llamarlo dos veces sin reventar por la clave única. Sin `email`, se inventa uno
  único (`e2e-<16 caracteres>@e2e.test`); sin `name`, «Usuario E2E»; sin
  `password`, `password` —la misma que siembra `E2eSeeder`, para que la cuenta
  sirva también para entrar por la UI real—. `role` tiene que ser uno de
  `App\Core\Enums\SystemRole` o responde 422 con la lista. `permissions` sólo se
  aplica si la clave viene: son permisos **directos**, además de los del rol, y
  es lo que distingue al «editor» del «viewer» sin inventarse un rol por cada
  matiz de autorización.
- **`users` (DELETE)** responde `{deleted: 0}` cuando ya no estaba. Cero no es un
  error, es «no había nada que borrar».
- **`artisan`** tiene lista blanca (`kore:regenerate-permissions`, `cache:clear`)
  y sólo esa. Un endpoint sin autenticación que ejecute artisan arbitrario es una
  shell remota, aunque viva detrás de tres candados.
- **`notify`** mete una notificación en la bandeja de alguien pasando por
  `App\Core\Contracts\Notifier`, el mismo contrato que usa la aplicación: lo que
  el spec ve después es el resultado del camino real —preferencias incluidas— y
  no un `INSERT` fabricado. Existe porque el producto no tiene ninguna pantalla
  desde la que crear una notificación a mano, así que sin él un spec de la
  bandeja tendría que provocar un login por API sólo para conseguir una fila que
  mirar. Manda con `mail: false` y `push: false` para no llenar el buzón de la
  suite. Responde **409** —y no un 500— cuando `NOTIFICATIONS_ENABLED` está
  apagado: el contrato sólo se bindea con el toggle encendido, y decirlo con su
  nombre ahorra leer un `BindingResolutionException` en el log de Playwright.
- **`throttle/clear`** limpia las claves que se le pasen y además vacía el
  almacén de caché por defecto: el limitador de Fortify combina correo e IP y no
  hay forma de enumerar sus claves. Es una base de pruebas; no hay nada ahí que
  valga la pena conservar. Existe porque la suite entera sale de una sola IP, y
  sin él, a partir de cierto punto, los tests fallarían con 429 por una razón que
  no tiene nada que ver con lo que probaban. Que el límite existe se comprueba en
  su propio spec, a propósito y limpiando después.

## El buzón de correo

Hay flujos que no se pueden probar sin abrir el correo: «olvidé mi contraseña»
manda un enlace con un token que en la base está hasheado, y el «código por
email» manda un código de un solo uso que sólo existe en el mensaje.

La cadena es ésta:

```
.env.e2e   MAIL_MAILER=log
           MAIL_LOG_CHANNEL=e2e_mail
              ↓ (config/mail.php → mailers.log.channel)
config/logging.php   canal `e2e_mail` → storage/logs/e2e-mail.log
              ↓
App\Modules\E2E\Support\MailLog  ←→  tests/e2e/support/mail-log.ts
              ↓
GET /__e2e__/mail/last?to=…
```

El canal es propio para no ir a pescar entre el ruido de `laravel.log`.
`MailLog` parte el archivo por la línea de log de Laravel, se queda con el último
bloque del destinatario —la suite corre en paralelo y varios tests mandan correos
a la vez, así que «el último» a secas puede ser el de otro worker— y devuelve
asunto, cuerpo, enlaces y el código de seis dígitos si lo hay.

Dos trampas que están resueltas en el código y conviene no volver a pisar:

1. **Las cabeceras se leen del bloque crudo, el cuerpo del decodificado.**
   Deshacer el quoted-printable borra los saltos de línea «suaves» (`=` al final
   de línea), y el `?=` con el que termina una palabra codificada
   (`=?utf-8?Q?c=C3=B3digo?=`) parece exactamente uno de ésos. El resultado era
   un asunto pegado a la cabecera siguiente:
   «Y otro =?utf-8?Q?más?MIME-Version: 1.0».
2. **Los trozos en base64 de un mensaje multiparte** decodificados como
   quoted-printable dejan bytes que no son UTF-8 válido, y `response()->json()`
   revienta con «Malformed UTF-8 characters». Se descartan.

`tests/e2e/support/env.ts` apunta al mismo archivo, así que el lector de Node y
el del harness ven exactamente lo mismo.

## El script de siembra

`scripts/e2e-seed.sh` deja la base como recién salida de fábrica:
`migrate:fresh --seed --seeder='Database\Seeders\E2eSeeder' --force --no-interaction --env=e2e` y buzón vacío. Se puede
correr solo para reparar la base a media sesión de trabajo.

Lleva **el mismo candado que `HarnessGuard`, pero en bash**: lee `DB_DATABASE`
de `.env.e2e` y aborta si el nombre no contiene «e2e» ni «test» (ni es `:memory:`). No es
redundancia: `migrate:fresh` borra la base **antes** de que ningún PHP tenga
ocasión de opinar, así que la comprobación tiene que estar arriba del todo.

```bash
./scripts/e2e-seed.sh
```

## El switcher de cuentas (`/dev/switch-account`)

El primo de andar por casa del harness, para las pruebas **manuales**: una
pantalla que lista las cuentas de demostración y entra como cualquiera de ellas
de un clic. Vive en el módulo Auth (`auth.dev-account-switcher`).

**Sólo existe en `local`, y por triplicado**: `Auth/Routes/web.php` no registra
la ruta fuera de local —así la URL es un 404 y no un 403, que delataría que hay
algo detrás—, `AuthModuleServiceProvider` no registra el alias del componente, y
`AuthDevImpersonateUserAction` vuelve a comprobarlo antes de tocar la sesión —la
llamada de Livewire viaja por `/livewire/update`, que no pasa por el middleware
de la ruta (R23)—.

El segundo candado de la Action es el dominio del correo: sólo entra en cuentas
de un **dominio reservado** (`.test`, `.example`, `.invalid`, `.localhost`,
`.local`, `example.com/net/org` — RFC 2606, 6761 y 6762). Son los que usan
`DatabaseSeeder` (`admin@example.com`) y `E2eSeeder` (`*@e2e.test`), y son los
únicos que no pueden pertenecer a una persona real. Sin ese candado, el atajo
entraría en la cuenta de cualquiera que hubiese acabado en una base de
desarrollo: un volcado de producción anonimizado a medias, por ejemplo. La regla
vive en `App\Modules\Auth\Support\DemoAccounts`, que es también quien filtra el
listado — si estuviera en los dos sitios, el día que alguien añadiera un dominio
se quedaría a medias.

**No es impersonation de verdad**: no se guarda la identidad original ni hay
«volver a ser admin». Para volver se elige otra cuenta.

## Toggle

| Variable | Default | Dónde se enciende |
|----------|---------|-------------------|
| `E2E_HARNESS` | `false` | `.env.e2e`, y sólo ahí |

`.env.example` lo trae en `false` y `phpunit.xml` lo fuerza a `false`, por lo
mismo que `DOCS_ENABLED`: si el desarrollador lo tuviera encendido en su `.env`,
los tests del caso «toggle apagado» fallarían sólo en su máquina. Los que
necesitan las rutas lo encienden con `withEnvironment()`
([`../patterns/test-con-otro-entorno.md`](../patterns/test-con-otro-entorno.md)).

## Tests

| Archivo | Qué blinda |
|---------|------------|
| `app/Modules/E2E/Tests/Feature/HarnessGuardTest.php` | los tres candados, uno a uno y en negativo; qué nombres de base cuentan como de pruebas; que `reasons()` nombra el que falla |
| `app/Modules/E2E/Tests/Feature/HarnessRoutesTest.php` | que con el toggle apagado las rutas **no existen**; y con él encendido, cada endpoint contra su contrato, correo incluido (mailer `log` de verdad sobre `e2e_mail`, nada de `Mail::fake()`) |
| `app/Modules/Auth/Tests/Feature/DevAccountSwitcherTest.php` | que el switcher no existe fuera de local, que en local lista, entra y rechaza una cuenta de dominio real |

Un detalle de los tests que conviene saber antes de escribir el siguiente:
`withEnvironment()` rearranca la aplicación y con ella la SQLite `:memory:`, así
que dentro de su callback **no hay base de datos**. Los tests que crean usuarios
encienden el harness con `Config::set()` + `register(force: true)`; el arranque
de verdad se prueba una vez, en el test que no toca la base.

## Reglas relacionadas

- **R10** — un toggle apagado no registra nada.
- **R11** — el toggle tiene un lector real (`HarnessGuard`).
- **R51** — el harness sólo existe con flag + entorno + base de pruebas.
