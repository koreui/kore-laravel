# Changelog

Todos los cambios relevantes de este boilerplate se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el versionado sigue [Semantic Versioning](https://semver.org/lang/es/).

Para un proyecto derivado, este archivo es la guía de qué aplicar al actualizar
desde el upstream (`git remote add kore https://github.com/koreui/kore-laravel`).

## [Unreleased]

## [2.2.0] - 2026-09-03

«API con contrato». Hasta la v2.1, `API_ENABLED` encendía una sola ruta que
devolvía el modelo Eloquent a pelo. Notarium y asper-server —los dos proyectos
de la cantera— habían reinventado por separado el envelope, el renderer de
errores, la paginación por cursor, los limiters por perfil y la documentación
OpenAPI. Esta release fija todo eso **una vez** en `App\Core\Http\Api` (R54),
y sobre ese contrato monta la autenticación por token, el CRUD de Users como
endpoint de referencia y un módulo opcional `Devices` para clientes móviles.

La suite Pest pasa de 511 a **693** tests (1956 assertions), la E2E se queda en
163, `config/kore-app.php` llega a **doce** claves con `DEVICES_ENABLED`, y el
catálogo a `R1..R54` con **47** reglas con verificador automático y **7**
manuales.

### Añadido

- **R54 · el contrato de la API vive en `App\Core\Http\Api`.** Toda respuesta de
  `api/*` tiene la misma forma sin que ningún endpoint la escriba: éxito en
  `{ data, meta? }`, fallo en `{ error: { code, message, details? } }`.
  - `Controllers/ApiController` — `respond(JsonResource|array $data, int $status = 200, array $meta = [])`
    y `respondNoContent()`. Trae `AuthorizesRequests` y `HandlesCursorPagination`.
  - `Resources/BaseApiResource` (`$wrap = 'data'`) y `Resources/EnumResource`
    (`{ value, label }`, con el `label()` del enum si lo tiene).
  - `Requests/BaseApiRequest` — `authorize()` a `true` (la decisión es de la
    Policy, R25) y `failedValidation()` que lanza la `ValidationException` limpia,
    sin el redirect que el `FormRequest` de Laravel construye para HTML.
  - `Concerns/HandlesCursorPagination` — `paginateWithCursor()`, `resolvePerPage()`
    y `cursorMeta()` (`next_cursor` / `prev_cursor` / `per_page`), con el tope de
    `per_page` en `config/kore-api.php`.
  - `Exceptions/ApiExceptionRenderer` — traduce cualquier `Throwable` de `api/*`
    a un código canónico de `App\Core\Enums\ApiErrorCode`: `validation_failed`
    (422, el único con `details`), `unauthenticated`, `forbidden`, `not_found`,
    `method_not_allowed`, `conflict`, `throttled` (con `Retry-After`),
    `bad_request`, `http_error` y `server_error`. Los mensajes salen del contrato
    y no de la excepción —el de un `ModelNotFoundException` filtra el modelo y el
    id—, y el 500 sólo añade `error.debug` con `APP_DEBUG=true`.
- **`App\Exceptions\ConflictException`** — excepción de dominio que el renderer
  convierte en 409. Una Action puede lanzarla sin romper R20.
- **Tres middleware con alias**: `api.json` (`ForceJsonResponse`) y `api.audit`
  (`ApiAuditLogger`, línea estructurada por petición en el canal `api`, nunca el
  cuerpo) van en el grupo `api` **por delante del throttle**; `api.cache`
  (`ApiCacheableResponse`: ETag + 304 + `Cache-Control: private`) se pone endpoint
  a endpoint.
- **`config/kore-api.php`** — `version`, `pagination` (25/100), `docs.enabled`
  (`API_DOCS`, false) y `limiters`. No duplica `API_ENABLED`.
- **Tres rate limiters nombrados** (R28) en `AppServiceProvider::configureApiRateLimiters()`:
  `api` (60/min por usuario o IP), `api-auth` (5/min **por IP**) y `api-uploads`
  (30/min por usuario).
- **Documentación OpenAPI (dedoc/scramble)** en `/api/docs` y `/api/docs.json`,
  detrás de `API_DOCS` y del gate `viewApiDocs` (libre en `local`, sólo superadmin
  fuera). `php artisan scramble:export` la vuelca en `storage/app/openapi.json`
  (gitignored). Provider: `App\Providers\ApiDocsServiceProvider`.
- **Canal de log `api`** en `config/logging.php` (stack, `LOG_API_STACK`, por
  defecto `single`).
- **`docs/guides/api.md`** — el contrato entero y cómo se añade un endpoint.

#### Autenticación por token y Users API v1

- **Autenticación por token (`api/v1/auth/*`)**. Cinco rutas: `POST /auth/login`
  (201 con `{ token, token_type, expires_at, user }`), `POST /auth/refresh`
  (rota el token con los permisos de ahora), `POST /auth/logout` y
  `POST /auth/logout-all` (204 sin cuerpo) y `GET /auth/me`, que es un alias del
  ya existente `api.v1.user.me` — el nombre viejo sigue funcionando.
- **Las abilities de un token son los permisos efectivos de su dueño**
  (`getAllPermissions()`), congeladas al emitirlo. Si el usuario no tiene
  ninguno, el token sale con `[]`, **nunca** con `['*']`.
- **Caducidad por token**: `API_TOKEN_EXPIRES_MINUTES` →
  `config('kore-api.tokens.expires_minutes')`, 43200 minutos (30 días) por
  defecto; `null` = sin caducidad. `sanctum.expiration` se queda en `null` a
  propósito: es global y **retroactiva**.
- **`RevokeApiTokensOnPermissionChange`**: cambiar el rol o los permisos de un
  usuario retira **todos** sus tokens de API y dispara
  `ApiTokenRevoked(reason: 'permissions_changed')`. Es R26 llevado al token,
  cuyas abilities de otro modo seguirían valiendo hasta caducar.
- **CRUD de usuarios por API** (`api/v1/users`), el endpoint de referencia del
  boilerplate: los cinco verbos, paginación por cursor con `meta`, filtros
  `search` y `role`, y **doble barrera** en cada ruta — la ability del token
  (`abilities:users.edit`) más la Policy dentro del controller (R23 · R25).
  Reutiliza las Actions, los DTOs y las reglas anti-escalada de la pantalla
  Livewire: no reimplementa nada.
- **Alias de middleware `abilities` y `ability`** (`CheckAbilities` /
  `CheckForAnyAbility`) en `bootstrap/app.php`. Sanctum trae las clases pero
  desde Laravel 11 ya no hay `Kernel.php` donde aliasearlas, así que sin estas
  dos líneas `->middleware('abilities:…')` revienta en runtime.
- **Código de error `two_factor_required` (403)** en `ApiErrorCode`, con su
  `App\Exceptions\TwoFactorRequiredException` y su rama en
  `ApiExceptionRenderer`. Mismo patrón que `ConflictException` → 409.
- **`permission.events_enabled` pasa a `true`** en `config/permission.php`. Sin
  esa clave spatie no dispara `RoleAttachedEvent` / `RoleDetachedEvent` /
  `PermissionAttachedEvent` / `PermissionDetachedEvent`, y el listener de
  revocación no se ejecutaría nunca sin que nadie se entere.
- Tests: `ApiAuthTest` (21), `ApiTokenRevocationTest` (11), `ApiUsersTest` (30),
  más un caso nuevo en `ApiExceptionRendererTest`, otro en
  `HandlesCursorPaginationTest`, otro en `ApiDocsTest` y otro en
  `AuthorizationSeederTest`.

#### Módulo `Devices`

- **Módulo `Devices`** (`app/Modules/Devices/`), el **duodécimo toggle** del
  boilerplate (`DEVICES_ENABLED`, apagado por defecto). Es el inventario de los
  clientes que consumen la API —una app móvil, un SPA, un CLI— y responde las
  tres preguntas que el token solo no responde: dónde tengo la sesión abierta, a
  qué dispositivo mando la notificación, y quién sigue con una versión que ya no
  soporto. Sale de la cantera de Notarium (`MobileDevice`, `DeviceController`,
  `EnsureMobileVersion`, `mobile:cleanup-*`), donde las tres estaban resueltas
  pero atadas a «móvil»; aquí el vocabulario es «cliente de API» y el CLI cuenta
  como dispositivo.
- **El módulo no registra a nadie: escucha** (R5). `RegisterDeviceOnTokenIssued`
  y `RevokeDeviceOnTokenRevoked` son toda la relación con Auth, vía
  `App\Modules\Auth\Events\{ApiTokenIssued, ApiTokenRevoked}`. Auth no sabe que
  Devices existe, y apagar el toggle deja de registrar los listeners sin que el
  login por API cambie una coma.
  - Con `deviceId`: `updateOrCreate` sobre `[user_id, device_id]` con `name`,
    `platform`, `app_version`, `access_token_id` y `last_seen_at`, y `revoked_at`
    de vuelta a `null` —volver a entrar es lo que resucita un dispositivo
    revocado—. **Sin `deviceId` no hace nada**: un token creado desde el panel
    web no identifica ningún aparato.
  - `ApiTokenRevoked` con `tokenId` revoca ese dispositivo; con `null` (logout de
    todos, cambio de contraseña, cuenta comprometida) revoca todos los del
    usuario, sin tocar la fecha de los que ya estaban revocados.
- **`Models/Device`** con `HasPublicUuid` + `ROUTE_BY_UUID`: la llave primaria
  sigue siendo entera —barata para el índice y para la FK contra
  `personal_access_tokens`— y el `uuid` es la identidad hacia afuera. Es el
  primer modelo del boilerplate que estrena el trait, que hasta ahora sólo tenía
  su test de laboratorio. `push_token` va `#[Hidden]` **y** fuera del resource:
  es una credencial de envío, y quien la tiene puede mandar notificaciones a ese
  teléfono.
- **`Enums/Platform`** (`ios`, `android`, `web`, `cli`) con `label()` y
  `supportsPush()`. No describe el aparato —para eso está `name`— sino los tres
  tipos de cliente que se tratan distinto. Lo que no esté en la lista blanca de
  `config('devices.platforms')` se guarda como `null` en vez de reventar el
  login: la plataforma es metadato, no una decisión de autorización.
- **Tres endpoints** bajo `api/v1`, detrás de `auth:sanctum` y de **los dos**
  toggles (`DEVICES_ENABLED` y `API_ENABLED`), con el contrato de R54:
  - `GET /api/v1/devices` — los del usuario del token, por cursor, con
    `current: bool` calculado contra el token de la petición.
  - `DELETE /api/v1/devices/{device:uuid}` — 204; revoca la fila **y borra el
    token de Sanctum** en una transacción. Si es el dispositivo actual, el token
    que se borra es el de esa misma petición: el 204 sale y la siguiente llamada
    es un 401, sin ningún caso especial.
  - `PUT /api/v1/devices/current/push-token` — 204; identifica el dispositivo por
    el token en uso y no por un id que mande el cliente, así que una app no puede
    reescribir el push token de otro dispositivo de la cuenta. No valida contra
    ningún proveedor: guarda.
  - **Ninguno acepta un `user_id`**: el dueño sale del token y la consulta se
    acota por él antes de buscar nada, así que el uuid de otro es un `404` y no
    un `403` que confirmaría que existe (criterio de las passkeys).
- **Middleware `devices.version`** (`EnsureClientVersion`), port de
  `EnsureMobileVersion`: lee `X-App-Version` y lanza `UpgradeRequiredException`, que `ApiExceptionRenderer`
  rinde como `426` `upgrade_required` (caso propio en `ApiErrorCode`, mismo
  camino que `ConflictException` → 409) por debajo de
  `config('devices.min_app_version')`.
  Sin cabecera deja pasar —los clientes web no la mandan y no se actualizan por
  una tienda—. Es **opt-in por ruta y no está en el grupo `api`**: el día que se
  sube el mínimo, el login y el propio listado de dispositivos tienen que seguir
  respondiendo para que la app pueda decirle al usuario qué hacer.
- **`devices:cleanup`** (`Console/Commands`, con `SupportsDryRun`), diario a las
  04:00 dentro de un `if` del toggle en `routes/console.php`. Tres pasos y dos
  relojes distintos: revoca los abandonados (`stale_after_days`, 180), borra sus
  tokens de Sanctum —los recién revocados, los ya revocados y los caducados que
  aún cuelgan de una fila— y purga los revocados hace más de `prune_after_days`
  (90). Primero se revoca por silencio y **después** empieza a correr la
  retención, así que lo revocado hoy no se borra hoy.
- **`config/devices.php`**: `min_app_version` (`0.0.0`), `prune_after_days` (90),
  `stale_after_days` (180) y `platforms`. Es a `kore-app.devices.enabled` lo que
  `config/kore-api.php` es a `kore-app.api.enabled`: el toggle dice si el módulo
  existe, este archivo dice cómo se comporta cuando existe.
- **`docs/modules/devices.md`**, enlazado en `docs/README.md`, con el modelo, los
  eventos que escucha, las rutas con su JSON, el middleware, el comando y la
  config. Sección corta «Dispositivos» en `docs/guides/api.md` y fila nueva en
  `docs/architecture/toggles.md`.
- **54 tests Pest nuevos** en `app/Modules/Devices/Tests/Feature/`:
  `DevicesToggleTest` (12), `DeviceListenersTest` (9), `DevicesApiTest` (14),
  `EnsureClientVersionTest` (6), `DevicesCleanupCommandTest` (10) y
  `DevicesConfigTest` (3).

### Cambiado

- **BREAKING · `GET /api/user` → `GET /api/v1/user`** (`api.user` → `api.v1.user.me`).
  La respuesta ya no es el modelo Eloquent a pelo: es
  `{ "data": { id, name, email, roles, permissions } }`
  (`App\Modules\Auth\Http\Resources\Api\V1\UserMeResource`).
- El limiter `api` se movió de `AuthModuleServiceProvider` a `AppServiceProvider`
  y ahora lee su cifra de `config/kore-api.php`.
- El preset `laravel()` de los arch tests ignora también `App\Core\Http\Api`.
- `withEnvironment()` (`tests/Pest.php`) reengancha la base SQLite en memoria
  después de cada `refreshApplication()`; antes, cualquier consulta dentro del
  bloque moría con `no such table`.
- `config/kore-app.php` pasa de once a **doce** claves con `devices.enabled`, y
  con ella `KoreMcpTest` (`kore-list-toggles`), la tabla de
  `docs/architecture/toggles.md` y el `about` de `AppServiceProvider`.
- `docs/patterns/toggle-provider.md` decía «diez claves hoy» desde la v1.3.0;
  ahora dice doce (R41).
- **`ApiErrorCode` gana `upgrade_required`** (426, `UpgradeRequiredException`,
  lanzada por el middleware `devices.version`), además del
  `two_factor_required` del login. Ningún middleware escribe ya un JSON de error
  a mano: R54 no tiene excepciones.

### Corregido

- **`AuthorizationCatalog::permissionsForRole()` devolvía `[]` en toda petición
  de la API, desactivando R26 en silencio.** Filtraba por
  `config('auth.defaults.guard')`, y `AuthManager::shouldUse()` —lo que llama
  `auth:sanctum`— **escribe** esa clave con el valor `sanctum`; los roles se
  siembran con `guard_name = 'web'`. Con la lista vacía, `GrantableRole` no
  encontraba ningún permiso «que el actor no tenga» y dejaba pasar cualquier
  rol: un token con `users.create` podía crear administradores. Ahora el guard
  sale de `Guard::getDefaultName(User::class)`, la misma resolución que usa
  spatie por dentro.
- **`ApiController::respond()` duplicaba `meta` en los listados.** Un
  `JsonResource` sobre un paginador se rinde por `PaginatedResourceResponse`,
  que añade su propio `meta` y lo funde con el del endpoint usando
  `array_merge_recursive`: el cliente recibía `meta.per_page = [25, 25]`. La
  forma que documenta `docs/guides/api.md` ahora produce un único `meta`.

### Migración desde 2.1.0

1. **La ruta de usuario cambió.** Si algún cliente llama a `GET /api/user`,
   muévelo a `GET /api/v1/user` y haz que lea bajo `data`. Si prefieres no tocar
   el cliente todavía, deja un alias en tu `Routes/api.php`:

   ```php
   Route::middleware(['api', 'auth:sanctum'])
       ->get('/api/user', [UserController::class, 'me'])
       ->name('api.user');   // deprecado: retíralo en cuanto migren los clientes
   ```

2. **Si ya tenías endpoints de API**, hazlos heredar del contrato o el arch test
   de R54 fallará: controllers de `Http/Controllers/Api` → `ApiController`,
   resources de `Http/Resources/Api` → `BaseApiResource`, requests de
   `Http/Requests/Api` → `BaseApiRequest`. Sus respuestas pasarán a venir
   envueltas en `data`: revisa los clientes antes de desplegar.

3. **Si tenías tu propio renderer de errores de API**, bórralo: el de
   `bootstrap/app.php` cubre todo `api/*`. Si tus códigos de error eran otros
   (`UNAUTHENTICATED` en mayúsculas, por ejemplo), decide si migras los clientes
   o si adaptas `ApiErrorCode` en tu fork — pero no dejes los dos.

4. **`.env`**: añade `API_DOCS=false` (en local, `true`). `.env.example` ya lo trae.

5. **`config/logging.php`**: si lo tienes publicado y modificado, añade el canal
   `api` a mano; sin él, el middleware `api.audit` no tiene dónde escribir.

6. **`config/scramble.php`**: se publica con `api_path => 'api/v*'` y
   `export_path => storage_path('app/openapi.json')`. Si ya lo tenías publicado,
   reconcilia esas dos claves y `dev_tools.enabled`.

7. **`phpunit.xml`**: añade `<env name="API_DOCS" value="false" force="true"/>`
   para que la suite no dependa del `.env` del desarrollador.

**Autenticación por token y Users API**:

1. **`config/permission.php`**: pon `'events_enabled' => true`. Si tu proyecto ya
   escucha esos cuatro eventos para otra cosa, revisa que reaccionar a los
   `syncRoles()` / `syncPermissions()` de tus seeders no sea un problema.
2. **`config/kore-api.php`**: añade el bloque `'tokens' => ['expires_minutes' =>
   env('API_TOKEN_EXPIRES_MINUTES', 43200)]` y la variable a tu `.env`. Si
   prefieres tokens sin caducidad, ponla a `null`; **no** toques
   `sanctum.expiration`, que caducaría también los tokens ya emitidos.
3. **`bootstrap/app.php`**: añade los alias `abilities` y `ability` si vas a usar
   `->middleware('abilities:…')`.
4. **`app/Modules/Auth/Support/AuthorizationCatalog.php`**: si lo copiaste antes
   de esta versión, cambia `config('auth.defaults.guard')` por
   `Guard::getDefaultName(User::class)`. **Es una corrección de seguridad**: sin
   ella, la anti-escalada no protege ninguna ruta de API.
5. Si tu API ya devolvía listados con `respond($resource, meta: …)`, comprueba si
   algún cliente se había adaptado al `meta.per_page` como array. Ahora es un
   entero, que es lo que el contrato prometía.
6. `GET /api/v1/auth/me` es un alias de `GET /api/v1/user`, no un reemplazo:
   el nombre `api.v1.user.me` sigue existiendo.
7. **Si tus usuarios tienen 2FA confirmado, no podrán pedir tokens de API**
   (403 `two_factor_required`). Es deliberado; si tu proyecto necesita otra
   política, el punto único donde cambiarla es
   `AuthTokenController::refuseWhenTwoFactorIsEnabled()`.

**Devices**:

- **Hay una migración nueva** (`app/Modules/Devices/Database/Migrations/2026_09_03_100000_create_devices_table.php`)
  y **se aplica aunque el toggle esté apagado**: un `php artisan migrate` crea la
  tabla `devices` en cualquier instalación. Es deliberado y es la misma decisión
  que `AUTH_PASSKEYS=false` —un toggle apaga rutas y comportamiento, nunca el
  esquema—: si la migración fuera condicional, dos instalaciones del mismo commit
  tendrían bases distintas según el `.env` del día en que se migró, y encender
  `DEVICES_ENABLED` en producción exigiría una migración a mano con tráfico
  encima. Su `down()` hace `dropIfExists` (R29) y `MigrationsAreReversibleTest`
  lo ejecuta.
- **Nada más cambia si no enciendes el toggle.** Sin `DEVICES_ENABLED=true` no
  hay rutas, ni alias de middleware, ni listeners, ni comando, ni tarea del
  scheduler.
- Para encenderlo: `DEVICES_ENABLED=true` en el `.env` (la clave nueva está
  documentada en `.env.example`, junto con la opcional
  `DEVICES_MIN_APP_VERSION`). Los endpoints piden además `API_ENABLED=true`.
- **El inventario se llena solo en cuanto Auth dispare `ApiTokenIssued` con
  `deviceId`.** Un derivado que ya tenga su propio login por token sólo tiene que
  disparar los dos eventos de `App\Modules\Auth\Events` con los datos del
  cliente; no hace falta llamar a ninguna Action de Devices.


## [2.1.0] - 2026-09-03

«La suite se defiende sola». Primera release que sale de la cantera de los
proyectos reales (`docs/audit/2026-09-03-cantera-notarium-asper.md`): todo lo
que entra existía ya en asper-server o en Notarium y se había pagado allí. Tres
cosas cambian de golpe la relación con la suite E2E: un **harness** con tres
candados para montar el escenario sin recorrerlo por pantalla, un **guardia de
errores** que pone en rojo un test verde sobre una pantalla rota, y un **mapa de
acceso** del que se generan solos el smoke y la matriz de autorización de cada
pantalla. Además, `App\Core\Concerns` estrena los traits que Notarium copiaba a
mano, el catálogo abre R3 a las carpetas que asper necesitó y aclara R5 sobre
eventos, y llegan R51, R52 y R53.

La suite Pest pasa de 410 a **511** tests y la E2E de 63 a **163** (104 generados
desde el mapa). El catálogo llega a `R1..R53` con **46** reglas con verificador
automático y **7** manuales.

### Añadido

- **Módulo `E2E`** (`app/Modules/E2E/`), el undécimo toggle del boilerplate.
  Cinco archivos de producción y dos de test, sin `Actions/` y sin `register()`
  en el provider: el harness es infraestructura de pruebas, no casos de uso del
  producto, y no importa a ningún otro módulo (R5) — trabaja sobre
  `App\Models\User`, que es global, y sobre `App\Core\Enums\SystemRole`.
- **`HarnessGuard`, los tres candados** (`Support/HarnessGuard.php`): el flag
  `config('kore-app.e2e.harness')`, el entorno en `['e2e', 'testing', 'local']`
  y una base de pruebas —nombre con «e2e» o «test», o `:memory:`—. La lista de
  entornos es una constante y no una clave de config a propósito: un candado que
  se amplía desde el `.env` no es un candado. `reasons()` devuelve en español
  cuál falla, que es lo que convierte «el harness no responde» en «la base se
  llama kore_prod».
- **Nueve rutas `/__e2e__/*`** (`Routes/web.php`, `Http/Controllers/HarnessController.php`),
  todas JSON, sobre `web` —varias necesitan sesión—, sin `auth` —su razón de ser
  es preparar el terreno antes de que haya sesión— y sin CSRF: `ping`,
  `login-as`, `logout`, `users` (POST y DELETE), `mail/last`, `mail` (DELETE),
  `artisan` y `throttle/clear`. `artisan` tiene lista blanca de dos comandos
  (`kore:regenerate-permissions`, `cache:clear`): un endpoint sin autenticación
  que ejecute artisan arbitrario es una shell remota, aunque viva detrás de tres
  candados.
- **Canal de log `e2e_mail`** (`config/logging.php` →
  `storage/logs/e2e-mail.log`) y **`MailLog`** para leerlo. Con
  `MAIL_LOG_CHANNEL=e2e_mail` en `.env.e2e`, el correo deja de mezclarse con el
  ruido de `laravel.log` y `GET /__e2e__/mail/last?to=` devuelve asunto, cuerpo,
  enlaces y el código de seis dígitos del último mensaje de ese destinatario
  —filtrar por destinatario, y no quedarse con «el último» a secas, es lo que
  permite que la suite siga corriendo en paralelo—.
- **`scripts/e2e-seed.sh`**: `migrate:fresh --seed --seeder=E2eSeeder --env=e2e`
  y buzón vacío, con **el mismo candado que `HarnessGuard` escrito en bash**. No
  es redundancia: `migrate:fresh` borra la base antes de que ningún PHP tenga
  ocasión de opinar, así que la comprobación tiene que estar arriba del todo.
- **Switcher de cuentas de desarrollo** en `/dev/switch-account`
  (`auth.dev-account-switcher` + `AuthDevImpersonateUserAction` +
  `Auth\Support\DemoAccounts`): lista las cuentas sembradas agrupadas por rol y
  entra como cualquiera de ellas de un clic, para las pruebas manuales de «qué
  ve un viewer». **Sólo en `local`, y por triplicado**: `AuthModuleServiceProvider` no registra ni la ruta ni el alias del componente; `DevAccountSwitcher::switchTo()` corta con `abort_unless(App::isLocal(), 403)` la llamada que llega por `/livewire/update`, que no pasa por el middleware de la ruta (R23); y `AuthDevImpersonateUserAction` vuelve a comprobarlo antes de tocar la sesión. Su segundo candado es el
  dominio del correo: sólo entra en cuentas de un **dominio reservado** (`.test`,
  `.example`, `.invalid`, `.localhost`, `.local`, `example.com/net/org`, RFC
  2606/6761/6762), que son las de los seeders y las únicas que no pueden ser de
  una persona real.
- **`docs/modules/e2e.md`**: los tres candados y por qué, la tabla de rutas con
  su JSON, la cadena del correo, el script y el switcher.
- **45 tests Pest nuevos**: `HarnessGuardTest` (13), `HarnessRoutesTest` (16) y
  `DevAccountSwitcherTest` (16).

- **Guardia de errores en todos los tests E2E** (`tests/e2e/fixtures/error-guard.ts`).
  Recoge excepciones de JavaScript (`pageerror`), errores de consola, respuestas
  `>= 400` y peticiones abortadas, y **hace fallar el test si hubo una excepción
  JS, un 5xx o un `console.error`** — aunque todas sus aserciones hayan pasado.
  Un test verde sobre una pantalla rota no sirve de nada. Los 4xx y las
  peticiones canceladas quedan como avisos anotados en el reporte, porque la
  matriz de acceso vive de los 403. Lo monta un `test.beforeEach` de
  `fixtures/index.ts` en **todos** los tests, los pidan o no.
  - Ruido conocido documentado uno a uno con su porqué (`favicon.ico`,
    `net::ERR_ABORTED` de una navegación que cancela un `fetch` de Livewire,
    `chrome-extension:`, chatter de Vite).
  - Opt-out explícito que obliga a decir **qué** se tolera:
    `test.use({ tolerarErrores: ['KORE-E2E-007'] })` para un describe, o
    `errores.tolerar('…')` dentro de un test.
- **Matriz de acceso como fuente única** (`tests/e2e/fixtures/access-map.ts`):
  14 pantallas × 5 perfiles (invitado, member, viewer, editor, superadmin), con
  seis resultados posibles — `200`, `403`, `404`, `'login'`, `'dashboard'`
  (rebote de `guest`) y `'confirm'` (rebote de `password.confirm`).
  De ahí salen **dos specs generados**:
  - `specs/access/rbac.spec.ts` — **70 tests**, con `page.request` y
    `maxRedirects: 0` para ver la redirección cruda.
  - `specs/access/smoke.spec.ts` — **34 tests**: cada pantalla que da 200 carga,
    muestra su heading, hidrata Livewire y no muestra claves de traducción sin
    resolver.
- **Cliente del harness** (`tests/e2e/fixtures/harness.ts`) para `/__e2e__/*`:
  `ping`, `estaDisponible`, `loginAs`, `logout`, `createUser`, `deleteUser`,
  `lastMail`, `esperarCorreo`, `clearMail`, `clearThrottle`, `artisan`. Fixture
  `harness` en todos los tests. `specs/harness/harness.spec.ts` se salta solo
  mientras `/__e2e__/ping` responda 404: **ningún otro spec depende del harness
  todavía**, así que la suite sigue verde sin el módulo `app/Modules/E2E`.
- **`scripts/e2e.sh`**, que es lo que corre `npm run e2e`: baja el `artisan
  serve` huérfano que ocupe el puerto (reconociendo al hijo `php -S …
  resources/server.php`, que es quien escucha de verdad, y a su padre), compila
  los assets si falta el manifest de Vite, delega el sembrado en
  `scripts/e2e-seed.sh` si existe y pasa los argumentos a Playwright tal cual.
  Variables: `E2E_PORT`, `E2E_BUILD`, `E2E_SKIP_SEED`.
- **`tests/e2e/HALLAZGOS.md`** — registro de lo que la suite encuentra, con
  identificadores `KORE-E2E-###`, estados (🔴 · 🟢 · 🔵) y plantilla. Siete
  hallazgos registrados con esta release: 2 corregidos, 3 documentados y 2
  abiertos.
- **`tests/e2e/FLUJOS.md`** — mapa de cobertura por flujo (A…I), con marcas
  ✅ · 🟡 · ⬜ · 🔒 y el spec que cubre cada uno.

- **`App\Core\Concerns` deja de estar vacía: tres traits para lo que un derivado
  copiaba a mano.** Salen de contar duplicaciones reales en Notarium y en
  asper-server, no de imaginar reutilización.
  - `HandlesDeleteConfirmation` — el par `confirmDelete(int $id)` /
    `deleteConfirmed()` de toda tabla, con el id pendiente bajo `#[Locked]`
    (R24) y el workaround de koreUi (`hydrate()` reponiendo `$koreConfirmable`)
    dentro. El componente sólo escribe `deleteAuthorized(int $id)`: `authorize()`
    + Action + toast. El hook es **público** a propósito, para que
    `kore:arch:check` siga exigiendo el `authorize()` en el archivo del módulo
    (R23). Ya lo usa `TableUsers`.
  - `RedirectsWithToast` — `redirectWithToast($ruta, $titulo, $mensaje, $params)`:
    toast **por sesión** (`viaSession()`, lo único que sobrevive a la
    redirección) + `to_route()`. Ya lo usa `FormComponent` de Users.
  - `HasPublicUuid` — **opt-in**, port del `GeneraUuid` de Notarium: rellena la
    columna `uuid` en `creating` dejando la llave primaria entera, y devuelve
    `uuid` como llave de ruta sólo si el modelo declara
    `public const bool ROUTE_BY_UUID = true;`. Sin migración de ejemplo: ningún
    modelo del boilerplate lo usa todavía.
- **`App\Core\Console\Concerns\SupportsDryRun`** — añade `--dry-run` a un comando
  sin tocar su `#[Signature]`, más `isDryRun()` y `dryRunNotice()`. Estrenado en
  `kore:regenerate-permissions`, que ahora dice cuántos permisos sincronizaría y
  a cuántos Administradores antes de tocar nada.
- **Monitores de cron en Sentry.** Todas las tareas de `routes/console.php`
  llevan `->sentryMonitor()`: un scheduler parado no genera ninguna excepción,
  así que sin check-ins se descubre el día que hace falta el backup. Sin
  `SENTRY_LARAVEL_DSN` la macro sigue existiendo y el check-in se va por un
  `return` temprano.
- **El 419 deja de ser una pantalla de error.** `bootstrap/app.php` redirige un
  `TokenMismatchException` de una petición web al `dashboard` si hay sesión y al
  `login` con un aviso si no. Livewire, las peticiones que esperan JSON y
  `api/*` conservan el 419 tal cual.
- **Skill `kore-migration-change`** (`.agents/skills/`, con su symlink en
  `.claude/skills/`, R49): obliga a leer el esquema real antes de escribir una
  migración con `->change()`, y a enseñar los atributos conservados antes de
  correrla. Implementa R53.
- **R51, R52 y R53** en el catálogo (`docs/architecture/rules.md`):
  - **R51 · El harness E2E sólo existe con flag + entorno + base de pruebas** —
    tres candados, no uno; el tercero (el nombre de la base) es el que protege
    cuando el `.env` se copia. Enforcement: `HarnessGuardTest`.
  - **R52 · Toda pantalla nueva entra en `tests/e2e/fixtures/access-map.ts`** —
    con check nuevo en `kore:arch:check`.
  - **R53 · Al modificar una columna se repiten todos sus atributos** — Manual +
    el skill `kore-migration-change`.

### Cambiado

- **Rector al set de PHP 8.4** (`withPhpSets(php84: true)` +
  `LevelSetList::UP_TO_PHP_84`): el mínimo real desde la v1.4.1 era 8.4 y el
  set seguía en 8.3. Aplicado: `array_any()` en dos bucles de `ArchCheckCommand`
  y `ListTogglesTool`, y `new X` sin paréntesis donde no hay argumentos.
- **GitHub Actions al día** (`actions/checkout` 7, `actions/cache` 6,
  `actions/setup-node` 7, `actions/upload-artifact` 7) y **`@types/node` 26**,
  **`concurrently` 10**. Los PRs de Dependabot llevaban abiertos desde la
  v1.0.0 con el CI en rojo; con el CI verde (v1.4.1) se fusionan los que pasan
  y el resto se aplica a mano en un solo commit.
- **`config/kore-app.php` gana su undécima clave**, `e2e.harness`
  (`E2E_HARNESS`, default `false`). `.env.example` la trae en `false`,
  `phpunit.xml` la fuerza a `false` —por lo mismo que `DOCS_ENABLED`: si el
  desarrollador la tuviera encendida en su `.env`, los tests del caso «toggle
  apagado» fallarían sólo en su máquina— y **`.env.e2e` es el único archivo del
  repositorio que la enciende**.
- **El correo de la suite cambia de archivo**: `tests/e2e/support/env.ts` apunta
  ahora a `storage/logs/e2e-mail.log` en vez de `storage/logs/laravel.log`. Es
  un cambio de una línea que arrastra `global-setup.ts` (que vacía ese archivo)
  y `support/mail-log.ts` (que lee el código OTP), y deja al lector de Node y al
  del harness mirando exactamente el mismo archivo.
- **`tests/Feature/KoreMcpTest.php`**: la tool `kore-list-toggles` pasa de diez a
  once claves.

- **`tests/e2e/fixtures/livewire.ts` absorbe `tests/e2e/support/livewire.ts`.**
  Se suma un **contador de peticiones en vuelo** instalado con el hook `request`
  del propio Livewire (`esperarLivewire`) y `conRoundTrip`, nombre nuevo de
  `withLivewireRoundTrip` — que se conserva como alias, con la misma semántica.
  La fixture `page` está sobrescrita para instrumentar antes del primer `goto`.
- **`npm run e2e`** pasa de `playwright test` a `bash scripts/e2e.sh`.
  `composer e2e` no cambia.
- `UserFormPage.save()` espera a la hidratación antes de pulsar (KORE-E2E-007).
- La suite pasa de **63 a 163 tests** (4 de ellos el proyecto `setup`).
  De los 159 reales, **104 se generan desde el mapa de acceso**.

- **R3 amplía la lista cerrada de carpetas de módulo** con `Enums/`,
  `Http/Resources/` y `Exports/`. Las dos primeras entran **con arch test**
  (enums backed; resources que extienden `JsonResource`); `Exports/` sólo con
  R3, porque lo que va dentro lo fija el paquete de exportación. `Services/`
  sigue fuera a propósito: es la carpeta que hay que justificar delante del
  equipo.
- **R5 aclarada: los `Events/` de otro módulo sí se pueden importar.** Son la
  frontera pública de un módulo; un listener tiene que poder tipar el evento que
  escucha. PHPat excluye ahora `{Otro}\Events` además de `{Otro}\Tests` en cada
  par de módulos, y `tests/Arch/PhpatArchitectureTest.php` comprueba la forma de
  la regla generada. Todo lo demás del módulo vecino sigue prohibido.
- `kore:arch:check` gana el check **R52** (`checkRoutesAreInAccessMap`): lee el
  texto de `routes/web.php` y `app/Modules/*/Routes/web.php` componiendo los
  `->prefix()` de los grupos, ignora las rutas con parámetro y las de
  `/__e2e__`, y exige que cada `path` aparezca en el mapa de acceso. Si el mapa
  todavía no existe, avisa una vez y **no** falla. Su válvula es de línea.
- `tests/Feature/ArchCheckCommandTest.php` pasa de 83 a **94 casos**;
  `tests/Arch` de 21 a **34 tests** (23 en `ArchitectureTest` + 11 en el nuevo
  `PhpatArchitectureTest`). La suite completa: **511 tests, 1243 assertions**.
- `phpstan.neon` añade `tests/Feature/HasPublicUuidTest.php` a `paths`: PHPStan
  no analiza el cuerpo de un trait que nadie usa, y ese test declara los dos
  modelos que estrenan `HasPublicUuid`.

### Eliminado

- **`tests/e2e/specs/auth/protected-routes.spec.ts`** (6 tests). Era la matriz
  de `/users` y `/users/create` escrita a mano; `specs/access/rbac.spec.ts` la
  cubre entera, con los cinco perfiles y catorce pantallas.
- **`tests/e2e/support/livewire.ts`**, absorbido por `fixtures/livewire.ts`.

### Corregido

- **KORE-E2E-001** · El detector de claves de traducción sin resolver cazaba 23
  falsos positivos en `/docs/architecture/rules`: el visor renderiza PHP y una
  llamada estática también lleva `::`. Ahora mira la prosa, recortando `pre`,
  `code`, `script` y `style` de una copia del DOM.
- **KORE-E2E-007** · El formulario de usuarios se enviaba como **GET nativo** si
  se pulsaba Guardar antes de que Livewire hidratara, dejando todos los campos
  —**contraseña incluida**— en la barra de direcciones. Los inputs se renderizan
  sin `value` y `wire:submit` no está enganchado hasta la hidratación.
  Corregido en la suite (`waitUntilReady()`) **y en el producto**: el botón
  «Guardar» de `form-component.blade.php` nace `disabled` y Alpine lo habilita
  al hidratar, que es cuando `wire:submit` ya escucha.

### Migración desde 2.0.0

Nada de esto toca rutas de producto, modelos ni base de datos. Tres bloques,
según lo que use tu derivado.

**Harness E2E** (sólo si usas la suite):

Un derivado que **no** use la suite E2E no tiene que hacer nada: el toggle nace
apagado y el módulo no registra nada.

Si la usa:

1. Copia `app/Modules/E2E/` y registra su provider **al final** de
   `bootstrap/providers.php`.
2. Añade la clave `e2e.harness` a tu `config/kore-app.php`, `E2E_HARNESS=false`
   a `.env.example` y `<env name="E2E_HARNESS" value="false" force="true"/>` a
   `phpunit.xml`.
3. Añade el canal `e2e_mail` a `config/logging.php` y, en tu `.env.e2e`,
   `E2E_HARNESS=true` y `MAIL_LOG_CHANNEL=e2e_mail`.
4. **Comprueba el nombre de tu base de E2E.** Si no contiene «e2e» ni «test», el
   tercer candado no se abre y el harness no existe: renómbrala. Es a propósito,
   y `HarnessGuard::reasons()` te lo dirá con esas palabras.
5. Si tu `tests/e2e/support/env.ts` apunta a `laravel.log`, cámbialo a
   `e2e-mail.log` en el mismo commit que el `.env.e2e`: si no, los specs que
   leen el OTP se quedan esperando un correo que ya está en otro archivo.
6. El switcher de cuentas es opcional y sólo afecta a `local`. Si tus cuentas de
   demostración **no** están en un dominio reservado, no aparecerán en la lista:
   la regla está en `App\Modules\Auth\Support\DemoAccounts` y es el único sitio
   que hay que tocar.

**Fixtures de Playwright**:

Cambios que hay que replicar a mano. Ninguno es automático.

#### 1. Copia las fixtures nuevas y borra la vieja

```bash
cp upstream/tests/e2e/fixtures/{error-guard,livewire,access-map,harness}.ts tests/e2e/fixtures/
cp upstream/tests/e2e/fixtures/index.ts tests/e2e/fixtures/
rm tests/e2e/support/livewire.ts
```

Repunta los page objects que importaban del sitio viejo:

```bash
grep -rl "support/livewire" tests/e2e \
  | xargs sed -i '' "s#from '../support/livewire'#from '../fixtures/livewire'#"
```

Si tu `fixtures/index.ts` está personalizado, no lo pises: trae de la versión
nueva las opciones `tolerarErrores`, las fixtures `errores` y `harness`, la
sobrescritura de `page` (que llama a `instrumentarLivewire`), el
`errores.vigilar(page)` dentro de `withRolePage` y el `test.beforeEach` final.

#### 2. Escribe **tu** `access-map.ts`

El del boilerplate describe las catorce pantallas del boilerplate; el tuyo tiene
que describir las tuyas. La estructura y los seis valores de `ResultadoAcceso`
se reutilizan tal cual; `PerfilAcceso` sale de los roles de tu `E2eSeeder`.

Si tus roles no son los cuatro del boilerplate, ajusta `PERFILES` y las claves
de `roles` — y **entonces** copia `specs/access/{rbac,smoke}.spec.ts`, que no
hay que tocar: leen el mapa.

Cuenta lo que te va a generar antes de correrlo: `rutas × perfiles` en RBAC más
las que valgan `200` en el smoke. Con 30 pantallas y 6 roles son 180 + N tests.

#### 3. Espera que el guardia encuentre cosas

En un proyecto con más superficie, **el guardia va a poner en rojo tests que
hoy pasan**. Es lo que hace. El camino no es apagarlo:

1. Corre la suite y anota cada error en tu `HALLAZGOS.md` con tu prefijo
   (`MIAPP-E2E-001`…).
2. Tolera sólo lo que no puedas arreglar hoy, con el patrón y el identificador:
   `test.use({ tolerarErrores: ['MIAPP-E2E-001'] })`.
3. Si es ruido del navegador, va a `RUIDO_CONOCIDO` **con su porqué**.

Si tienes muchísimo, un aterrizaje suave es sacar `'consola'` de `graves()`
durante una release —dejando sólo excepciones y 5xx— y volver a meterlo cuando
la lista esté vacía. Es un `arch-accepted` con dueño, no una decisión silenciosa.

#### 4. `scripts/e2e.sh` y `package.json`

```json
"e2e": "bash scripts/e2e.sh",
```

Revisa dentro del script el puerto (sale de `APP_URL` en tu `.env.e2e`) y si
tienes `scripts/e2e-seed.sh`. Si tu base no es SQLite, seguramente lo tengas y
el script lo llamará solo.

#### 5. Los dos cuadernos

`tests/e2e/HALLAZGOS.md` y `tests/e2e/FLUJOS.md` **no se copian**: se empiezan.
Coge del boilerplate las cabeceras, la plantilla y la leyenda; el contenido es
tuyo. Van en `tests/e2e/` y no en `docs/` a propósito: no pasan por el índice de
`docs/README.md` (R40), se editan en el mismo commit que los tests, y se citan
desde `docs/quality/e2e.md`.

#### 6. Lo que se rompe si no haces nada

| Si no… | Qué pasa |
| --- | --- |
| copias `fixtures/index.ts` | No hay guardia: la suite sigue igual que antes |
| borras `support/livewire.ts` y repuntas los imports | Dos contadores distintos y `esperarLivewire` sin instrumentar |
| escribes tu `access-map.ts` | `specs/access/*` no compila (no existe el módulo) |
| añades R52 a `rules.md` antes que las citas | `kore:arch:check` falla: cita una regla que no existe |

**Core, bonus y catálogo**:

1. **Tablas y formularios (opcional, pero es donde está la ganancia).**
   En cada `Table{X} extends KoreDataTable`:
   - añade `use App\Core\Concerns\HandlesDeleteConfirmation;`,
   - borra el `hydrate()` con el workaround de `$koreConfirmable` (lo trae el
     trait),
   - renombra `confirmDelete(int $id)` a `deleteAuthorized(int $id)` y déjalo
     **público**. El `RowAction` sigue apuntando a `wireMethod('confirmDelete')`:
     ese método ahora lo pone el trait.

   En cada `FormComponent`: añade `use App\Core\Concerns\RedirectsWithToast;` y
   sustituye el bloque `toast()->…->viaSession()->send(); return to_route(…);`
   por `return $this->redirectWithToast('ruta.index', __('¡Listo!'), __('…'));`.

   Los tests que llaman a `->call('confirmDelete', $id)` siguen funcionando sin
   tocarlos.

2. **419.** El bloque `withExceptions` de `bootstrap/app.php` es nuevo. Si tu
   proyecto ya redirige los 419 por su cuenta, no lo dupliques. Si tienes rutas
   de API fuera del prefijo `api/`, ajusta la condición: la que viene mira
   `expectsJson()`, la cabecera `X-Livewire` y `api/*`.

3. **Sentry.** `->sentryMonitor()` en cada tarea de tu `routes/console.php`. Sin
   DSN es no-op, así que se puede añadir antes de tener Sentry configurado. En
   producción, revisa los monitores que aparecen en Sentry y ponles el margen
   (`checkInMargin`) que les corresponda.

4. **R3 · carpetas nuevas.** Si ya tenías `Enums/`, `Http/Resources/` o
   `Exports/` en tus módulos —y lo más probable es que sí—, ahora son legales.
   Comprueba que tus enums son **backed** y que tus resources extienden
   `JsonResource`: son los dos arch tests nuevos. Si tienes `Services/`, sigue
   fuera: mira si son Actions (lo habitual), `Support/` o `Data/`.

5. **R5 · eventos entre módulos.** Si tenías listeners registrados con el nombre
   del evento en un string para esquivar PHPat, ya puedes volver a
   `Event::listen(Evento::class, Listener::class)` con el `use` de verdad.

6. **R52 · mapa de acceso.** Cuando fusiones `tests/e2e/fixtures/access-map.ts`,
   `composer arch` empezará a exigir una fila por cada ruta `GET` con nombre.
   Corre `php artisan kore:arch:check --rule=R52` para ver la lista antes de
   escribirla. Sin el archivo, el check sólo avisa.

7. **`HasPublicUuid` es opt-in.** No hay migración: si lo quieres, añade
   `$table->uuid('uuid')->nullable()->unique()->after('id');` a tu tabla y el
   trait al modelo.

## [2.0.0] - 2026-09-03

«Laravel 13 + Pest 5». Primer *major* del boilerplate: sube el framework, el
runner de tests y tres paquetes con major nuevo, adopta los idiomas de Laravel
13 (`#[Fillable]`, `#[Signature]`, `#[Description]`), activa el endurecimiento
de serialización que trae el esqueleto 13 y añade **passkeys (WebAuthn)** como
toggle `AUTH_PASSKEYS`. koreUi no necesitó ni una línea de código: sólo ampliar
su constraint a `^12.0|^13.0` (v2.3.1), verificado con sus 2200 tests sobre
Laravel 13.30 y con la suite E2E entera del boilerplate.

La suite Pest pasa de 391 a **410** tests y la E2E de 57 a **63** (dos de los seis
de passkeys firman con un autenticador WebAuthn virtual por CDP).

### Cambiado

- **Laravel 12.69 → 13.30** (`laravel/framework ^13.0`). Lo que hubo que tocar
  en el boilerplate: **nada de código de aplicación**. `bootstrap/app.php`,
  `config/cache.php` y `config/session.php` ya usaban el formato de prefijos con
  guiones de 13 (así que **no se invalidan cachés ni cookies al subir**),
  `config/permission.php` y la migración de permisos son idénticos al stub de
  v8, y `tests/Pest.php` y los arch tests no cambian de API. Dos claves nuevas en
  `lang/es.json` porque Laravel 13 cambió el asunto de dos notificaciones del
  framework (`Reset your password`, `Verify your email address`; la segunda no
  está en la guía de upgrade y la detectó `TranslationsTest`).
- **Pest 3.8 → 5.1, PHPUnit 11.5 → 13.3**, `pest-plugin-laravel` 5. Sin cambios
  en `tests/Pest.php`, `phpunit.xml` (`pest --migrate-configuration` no tiene
  nada que migrar) ni en `tests/Arch/ArchitectureTest.php`: los presets `php()`,
  `security()` y `laravel()->ignoring([...])` son los mismos. Ninguna regla se
  relajó.
- **`spatie/laravel-permission` 7.4 → 8.3** y **`laravel/tinker` 2 → 3**. Los
  renombres de permission v8 (`PermissionAttached` → `PermissionAttachedEvent`,
  `CacheReset` → `CacheResetCommand`) no tocan nada del proyecto; `Role`,
  `ModulesSeeder` y `AuthorizationCatalog` quedan igual.
- **Vite 7 → 8.2, `laravel-vite-plugin` 2 → 3.2**. `vite.config.js` sin cambios.
- **Idiomas de Laravel 13 vía Rector** (`LaravelLevelSetList::UP_TO_LARAVEL_130`):
  `#[Fillable]` y `#[Hidden]` en `User` y `Module` en vez de `$fillable` /
  `$hidden`, y `#[Signature]` / `#[Description]` en todos los comandos (Core, Auth y Tenancy). R27
  («mass assignment explícito») se cumple igual; el catálogo y
  `module-pattern.md` lo dicen con el atributo.
- **Endurecimiento del esqueleto 13**: `config/cache.php` →
  `'serializable_classes' => false` (la caché deja de `unserialize()` objetos:
  cierra cadenas de gadgets si se filtra `APP_KEY`) y `config/session.php` →
  `'serialization' => 'json'` (una sesión manipulada no puede instanciar clases).
  `PreventRequestForgery` ya viene en el grupo `web` de 13 sin tocar
  `bootstrap/app.php`; `config/sanctum.php` conserva el alias que publica el
  paquete.
- **CI**: matriz PHP 8.4 / 8.5. `composer why-not php 8.5` no encuentra ningún
  bloqueo y el `polyfill-php85` que arrastra Laravel 13 (`array_first()`,
  `array_last()`) no choca con nada del proyecto.
- **Boost**: guidelines regeneradas para Laravel 13 (`boost:install
  --guidelines`) y `AGENTS.md` regenerado con `kore:agents:sync`. Los cuatro
  skills propios declaran `compatibility: Laravel 13, Livewire 4, Pest 5`.
- **Decisión: los atributos `#[Middleware]` / `#[Authorize]` de Laravel 13 no se
  aplican.** Son de *controller*, no valen para Livewire (R23 se queda como
  está: la autorización vive en el componente porque `/livewire/update` no pasa
  por el middleware de la ruta), y en los tres controllers de rutas que tenemos
  moverían la política de acceso fuera de `Routes/web.php`, que es donde hoy se
  lee de un vistazo quién entra. Documentado en `authorization.md`.
- **`stancl/tenancy` se queda en 3.10**: no hay v4 estable y 3.10 declara
  Laravel 13. `tenancy.md` avisa del cambio de precedencia de rutas con dominio
  de Laravel 13.

### Añadido

- **Passkeys (WebAuthn) detrás del toggle `AUTH_PASSKEYS`** (encendido por
  defecto). Entrar con la huella, la cara o el PIN del dispositivo, sin
  contraseña. La criptografía la ponen `laravel/passkeys` 0.2 y Fortify 1.39
  (`Features::passkeys(['confirmPassword' => true])`); el boilerplate añade el
  toggle, la pantalla, el botón del login y el rate limit.
  - **Toggle**: `config/kore-app.php` → `auth.passkeys`. Décima —y por ahora
    última— clave del archivo. Lo aplica
    `FortifyServiceProvider::configurePasskeysFeature()` desde `register()`,
    porque `config/fortify.php` no puede leer `kore-app` (R12): es la **variante
    B** del patrón `docs/patterns/toggle-provider.md`, la misma que ya usaba el
    2FA, repetida sin cambiarle una coma.
  - **Pantalla `/user/passkeys`** (`passkeys.index`): componente Livewire
    `App\Modules\Auth\Http\Livewire\Passkeys`, con alta, listado y revocación.
    Enlazada desde el sidebar («Cuenta → Passkeys») y desde el dashboard.
  - **Botón «Entrar con passkey»** en `/login`, sólo si la ruta
    `passkey.login` existe. Los errores del navegador (cancelación, dispositivo
    no soportado, dominio inválido) se pintan en un `<x-kore::alert>` sin
    recargar, traducidos por `error.name` y no por su texto en inglés (R33).
  - **Cliente JS oficial**: `@laravel/passkeys` (dependencia npm nueva), envuelto
    en el componente Alpine `korePasskeys` de `resources/js/app.js`.
  - **`config/fortify.php` → `passkeys`**: `relying_party_id` y
    `allowed_origins` derivados de `APP_URL` con `env()` (nunca
    `config('app.url')`: R12), `user_handle_secret` desde `APP_KEY` con
    `PASSKEYS_USER_HANDLE_SECRET` como escape para rotarla, y `timeout` de 60 s.
    `config/passkeys.php` **no** se publica: Fortify sobrescribe sus claves.
  - **Rate limit `passkeys`** (R28): `fortify.limiters.passkeys`, 30/min por
    usuario cuando lo hay y por IP cuando no (`POST /passkeys/login` es un login
    sin contraseña). Cuelga de seis de las siete rutas que publica Fortify (la de borrado no lleva
    throttle).
  - **Tabla `passkeys`**: migración publicada del paquete y adaptada al
    boilerplate (`declare(strict_types=1)` y docblock; el `down()` ya venía, R29). Se crea
    aunque el toggle esté apagado: el toggle apaga rutas y UI, no el esquema.
  - **Tests**: `PasskeysToggleTest` (9) y `PasskeysScreenTest` (10) en Pest, y
    `tests/e2e/specs/auth/passkeys.spec.ts` (6) en Playwright, con un
    **autenticador WebAuthn virtual por CDP** que firma de verdad: registrar →
    cerrar sesión → entrar con passkey, sin ningún dispositivo real.

### Cambiado

- **`.env.e2e` usa `http://localhost:8010`** en vez de `http://127.0.0.1:8010`.
  El *relying party id* de WebAuthn tiene que ser un dominio y los navegadores
  rechazan los literales IP, así que con `127.0.0.1` la suite E2E no podría
  ejercitar passkeys. `localhost` además cuenta como origen seguro sin TLS.
  Arrastra dos ajustes menores: el `webServer` de Playwright arranca con
  `--host=localhost` (sale de `APP_URL`) y `specs/auth/login.spec.ts` compara la
  URL de la landing contra `localhost`.
- **`app/Models/User`** implementa `Laravel\Fortify\Contracts\PasskeyUser` y usa
  el trait `Laravel\Fortify\PasskeyAuthenticatable`.
- **`LoginPage.submit`** (page object de E2E) pasa a `{ name: 'Entrar', exact: true }`:
  sin `exact` también casaba el botón «Entrar con passkey» nuevo.

### Seguridad

- **La revocación de una passkey no la alcanza ni el superadmin.** El
  `Gate::before` de `AuthModuleServiceProvider` devuelve `true` para ese rol ante
  cualquier ability, así que una policy sola no bastaría: la credencial se busca
  **dentro de `$user->passkeys()`** y un id ajeno es un 404, no una decisión del
  Gate. `PasskeyPolicy` queda como segunda barrera y punto único de la regla
  (R25). Una passkey es la llave de una persona, no un recurso administrable.
- **La confirmación de contraseña se comprueba dos veces.** La pantalla lleva el
  middleware `password.confirm` —así la confirmación ocurre **antes** de la
  ceremonia y no a mitad, con la credencial recién creada perdida— y
  `Passkeys::deletePasskey()` la vuelve a comprobar dentro, porque
  `/livewire/update` no pasa por el middleware de la ruta (R23) y la ventana de
  `auth.password_timeout` puede caducar con la pantalla abierta.

### Migración desde 1.4.1

Es un *major*: léelo entero antes de mergear.

1. **PHP 8.4+ y koreUi ≥ 2.3.1** (la primera con `illuminate/* ^12.0|^13.0`).
2. **Constraints** (`composer.json`): `laravel/framework ^13.0`,
   `laravel/tinker ^3.0`, `spatie/laravel-permission ^8.0`, `pestphp/pest ^5.0`,
   `pestphp/pest-plugin-laravel ^5.0`, `phpunit/phpunit ^13.0`,
   `kore-ui/kore-ui ^2.3`. Después `composer update --with-all-dependencies`.
   Lee la guía oficial de upgrade de Laravel 13 y la de Pest 5: lo que este
   boilerplate no tocaba puede que tu derivado sí (por ejemplo `upsert()`,
   `Js::from()` con unicode, o rutas con `->domain()`).
3. **Cachés y sesiones**: si tu `config/cache.php` y `config/session.php` siguen
   con los prefijos antiguos (`_cache_`, `_session`), al subir se invalidan; los
   del boilerplate ya usaban guiones desde v1.0. **`session.serialization =
   'json'` invalida todas las sesiones activas**: despliega en ventana de
   mantenimiento o déjalo en `php` hasta la siguiente.
4. **Traducciones**: añade a `lang/es.json` `"Reset your password"` y
   `"Verify your email address"` (o corre `TranslationsTest`, que te lo dirá).
5. **Rector**: `LaravelLevelSetList::UP_TO_LARAVEL_130` en `rector.php` y
   `vendor/bin/rector process` para pasar a atributos. Revisa el diff: Rector
   borra los docblocks de las propiedades que convierte.
6. **npm**: `vite@^8`, `laravel-vite-plugin@^3`, `npm install`, `npm run build`.
7. **CI**: matriz `['8.4', '8.5']`.
8. **Boost**: `php artisan boost:install --guidelines` y `php artisan
   kore:agents:sync`.

**Passkeys** (llegan encendidas; con `AUTH_PASSKEYS=false` no se registra nada:
ni rutas, ni pantalla, ni botón). Si las quieres:

1. **`composer install`** (Fortify 1.39 arrastra `laravel/passkeys`) y
   **`npm install`** (dependencia npm nueva: `@laravel/passkeys`).
2. **`php artisan migrate`** — crea la tabla `passkeys`. Si tu proyecto ya tenía
   una migración de passkeys publicada del paquete, quédate con una sola: la del
   boilerplate se llama `2026_09_03_000000_create_passkeys_table.php`.
3. **`App\Models\User`**: añade `implements PasskeyUser` y
   `use PasskeyAuthenticatable` (los de `Laravel\Fortify`, no los de
   `Laravel\Passkeys` — el trait de Fortify envuelve al del paquete).
4. **`config/fortify.php`**: copia el bloque `passkeys` y la clave
   `limiters.passkeys => 'passkeys'`. Si tu `config/fortify.php` está muy
   tocado, sólo hacen falta esas dos cosas.
5. **`APP_URL` tiene que ser el dominio real y con `https://`** (o `localhost` en
   desarrollo). Es de donde sale el *relying party id*, y **cambiarlo después
   invalida todas las passkeys registradas**: no hay migración, cada usuario
   tiene que registrar la suya de nuevo. Si tu dominio aún puede cambiar,
   despliega con `AUTH_PASSKEYS=false`.

Dos avisos más:

- Si algún día rotas `APP_KEY`, fija antes `PASSKEYS_USER_HANDLE_SECRET` con el
  valor viejo: el user handle de WebAuthn se deriva de ahí y rotar sin fijarlo
  invalida las credenciales.
- Si tu suite E2E vive en `127.0.0.1`, cámbiala a `localhost` para poder probar
  passkeys (ver el snippet de `docs/quality/e2e.md`).

### Corregido

- **`withEnvironment()` repite `withoutVite()` tras cada `refreshApplication()`.**
  La aplicación reconstruida no pasa por `TestCase::setUp()`, así que perdía el
  `withoutVite()` y toda vista con `@vite` buscaba `public/build/manifest.json`.
  En local existe y `DocsPagesTest` pasaba; en CI no, y daba 500. Era el último
  rojo del workflow `ci.yml`.

## [1.4.1] - 2026-09-03

### Corregido

- **El workflow `ci.yml` llevaba en rojo desde la v1.0.0** por dos causas que
  ninguna release había mirado (los hooks locales y el E2E sí pasaban, y el
  E2E instala Composer). (1) El job `assets` hacía `npm run build` sin
  `composer install`, y `resources/css/app.css` importa el tema de koreUi desde
  `vendor/kore-ui/kore-ui/…`: «Can't resolve». Ahora instala PHP y Composer
  (con caché) antes de Node. (2) El job `quality` corría Pest sin `.env`, y
  toda petición web moría con `MissingAppKeyException`. `phpunit.xml` fija ahora
  una `APP_KEY` de pruebas desechable con `force="true"`: la suite deja de
  depender de la clave del desarrollador y pasa igual con `.env`, sin él o con
  uno copiado de `.env.example` (los tres escenarios, comprobados).

### Cambiado

- **PHP 8.4 es el mínimo** (`composer.json` → `"php": "^8.4"`; la matriz de CI
  pasa de 8.3/8.4 a 8.4). No es una decisión nueva sino la que el lock ya había
  tomado sin decirlo: `spatie/laravel-activitylog` 5, `laravel-one-time-passwords`
  1.1 y Symfony 8.1 exigen 8.4, así que el job de PHP 8.3 fallaba en
  `composer install` con «Your lock file does not contain a compatible set of
  packages» y el boilerplate nunca se pudo instalar en 8.3. Los docs que decían
  «PHP 8.3+ (soporta 8.4)» dicen ahora 8.4+ (R41). PHP 8.5 entrará con la v2.0.

### Migración desde 1.4.0

Si tu derivado corre en PHP 8.3, ya no instalaba este lock: sube a 8.4 (la
imagen de producción, `php:8.4-fpm-alpine`, ya lo era). Copia después los tres cambios (`.github/workflows/ci.yml` → pasos de PHP y Composer en
el job `assets`; `phpunit.xml` → `<env name="APP_KEY" …>`). Genera tu propia
clave con `php artisan key:generate --show`: es sólo para tests, pero no
conviene compartirla entre repositorios.

## [1.4.0] - 2026-09-03

«DX y AI tooling». Tres cosas del repositorio se mantenían a mano y sólo se
verificaban acordándose: los ocho skills estaban duplicados byte a byte en
`.claude/skills/` y `.agents/skills/`, `AGENTS.md` era una copia manual de
`CLAUDE.md`, y los conventional commits eran una regla **Manual · Warning** con
un «hay hueco para un hook» escrito al lado. Esta release convierte las tres en
código: una carpeta real y ocho symlinks, un comando que genera, y un hook que
rechaza. Y le da al agente dos cosas que hasta ahora tenía que buscarse
abriendo medio repositorio: un **MCP server propio** (`kore`) que responde por
módulos, toggles, permisos y reglas, y un **visor de `docs/` en `/docs`**
servido por la propia aplicación. Alrededor del repositorio, lo que faltaba:
plantillas de PR e issue, `CODEOWNERS`, un workflow de release que **se niega a
publicar un tag sin su sección del CHANGELOG**, la guía de actualización para
derivados y los dos primeros patrones documentados con la regla de tres.

La suite Pest pasa de 277 a **391** tests, la E2E de 45 a **57**, y el catálogo
de `R1..R48` a `R1..R50`, con **44** reglas con verificador automático (eran 40).

### Añadido

- **MCP server propio, `kore`** (`app/Core/Mcp/KoreServer.php`, registrado en
  `routes/ai.php` con `Mcp::local('kore', KoreServer::class)`). Se arranca con
  `php artisan mcp:start kore` y lo declaran `.mcp.json` y `.codex/config.toml`,
  que siguen siendo espejo el uno del otro. `routes/ai.php` es un archivo que
  `laravel/mcp` (ya instalado como dependencia de Boost) carga solo si existe,
  así que no toca `bootstrap/app.php`. Convive con `laravel-boost` sin solaparse:
  Boost responde sobre **el framework** (esquema, queries, docs de paquetes,
  tinker) y `kore` responde sobre **este proyecto**.
- **Cinco herramientas, todas `readOnlyHint` + `idempotentHint`:**
  `kore-list-modules` (inventario de `app/Modules/*` leyendo el sistema de
  archivos: provider, si está registrado, carpetas de la lista cerrada de R3,
  Actions, Livewire, rutas y tests; no instancia una sola clase de
  `App\Modules`, R6), `kore-list-toggles` (las nueve claves de `kore-app` con su
  valor, su variable de `.env`, su default y **la lista de archivos que las
  leen**, más las claves que encienden capacidades sin ser toggles),
  `kore-list-permissions` (roles, matriz de permisos y qué hay sembrado, todo por
  `App\Core\Contracts\AuthorizationCatalog`; si la base no responde degrada al
  catálogo estático con aviso), `kore-get-rule` (una regla del catálogo por
  número, o la tabla resumen; una regla inexistente devuelve `Response::error()`,
  no una excepción) y `kore-arch-check` (ejecuta exactamente
  `kore:arch:check`, sin parámetro para elegir otro comando: una tool que acepta
  un comando arbitrario deja de ser un linter y pasa a ser una shell remota).
- **Ninguna tool devuelve un secreto.** Cualquier clave cuyo nombre contenga
  `token`, `password`, `secret`, `key`, `dsn`, `passphrase` o `credential` se
  responde como `configurado` / `sin configurar`. Lo cubre un test.
- **`tests/Feature/KoreMcpTest.php`** (15 tests) con el helper de test de
  `laravel/mcp` (`KoreServer::tool(...)`), que manda un `tools/call` real por un
  transporte falso: cubre nombre, esquema y serialización, no sólo `handle()`.
- **Visor de documentación en `/docs`, detrás del toggle `DOCS_ENABLED`**
  (default `false`; `.env.example` lo trae en `true`, `.env.e2e` también y
  `phpunit.xml` lo fuerza a `false`). La landing enlazaba a GitHub porque
  `/docs` daba 404; ahora los mismos `docs/*.md` se sirven renderizados con la
  UI de la app, y con el toggle apagado los enlaces vuelven a apuntar a GitHub
  solos. La fuente sigue siendo única: `docs/`, lo que se revisa en el PR y lo
  que R40 vigila.
- **Módulo `app/Modules/Docs/`** con la lista cerrada de R3 y sin `Actions/`
  (leer un archivo y convertirlo a HTML es una consulta, no un caso de uso):
  `DocsController` con dos métodos delgados, `MarkdownRenderer` y
  `DocLinkExtension` en `Support/`, `DocumentData` en `Data/`, dos Blade y su
  `en.json`. El renderizado es `Str::markdown()` —el GFM que Laravel ya trae:
  tablas, listas de tareas, código, **cero dependencias nuevas**— con
  `html_input => 'strip'` y `allow_unsafe_links => false`.
- **Reescritura de los enlaces entre documentos con una extensión de
  CommonMark, no con una regex.** `../architecture/rules.md` →
  `/docs/architecture/rules`, `rules.md#r11` → `/docs/architecture/rules#r11`,
  `../README.md` → `/docs`, y lo que cae fuera de `docs/` (`../../CHANGELOG.md`)
  → GitHub. Un `](...)` también aparece dentro de un span de código
  (`docs/audit/…` cita literalmente `` `[koreUi](../koreUi)` ``) y una regex lo
  reescribiría; la extensión trabaja sobre el árbol parseado. De paso pone el
  `id` de cada encabezado con el mismo slug que GitHub (acentos incluidos) y
  recoge los `##` para el índice lateral con los mismos slugs.
- **Seguridad del `path` por partida doble**: la ruta restringe `{path}` a
  `[A-Za-z0-9_\-/]+` (sin puntos, así que `..` no casa con ninguna ruta) y
  `DocsController::resolve()` repite la comprobación sobre el archivo ya resuelto
  (`realpath` + prefijo de `base_path('docs')`, que cierra también los symlinks
  que apunten fuera). Redundante a propósito: el día que alguien relaje el
  `where()`, `/docs/../.env` tiene que seguir siendo un 404.
- **43 tests del módulo Docs** (`DocsToggleTest` 5, `DocsPagesTest` 13 —cinco
  formas de intentar salirse de `docs/`, todas 404—, `MarkdownRendererTest` 25) y
  **12 E2E** en `tests/e2e/specs/docs/` (smoke, navegación entre documentos con
  breadcrumb e índice lateral, y «autorización»: público, sin escapes, 404 para
  lo que no existe) con su page object `DocsPage.ts` (R36).
- **`php artisan kore:agents:sync`** (`app/Core/Console/AgentsSyncCommand.php`) —
  genera `AGENTS.md` desde `CLAUDE.md`: una cabecera en comentario
  HTML («Generado desde CLAUDE.md… No edites este archivo») más el original
  íntegro debajo. Con `--check` no escribe nada y devuelve exit 1 si el generado
  no coincide. La lógica de «qué debería contener» vive en
  `App\Core\Support\AgentsFile`, para que el comando y el check no la dupliquen
  (R50).
- **`php artisan kore:changelog:section vX.Y.Z`**
  (`app/Core/Console/ChangelogSectionCommand.php`) — imprime la sección
  `## [X.Y.Z]` del `CHANGELOG.md`. Acepta el tag con o sin `v`. Devuelve exit 1
  si la sección no existe **o está vacía**: es lo que hace que R42 deje de
  depender del review.
- **Hook `commit-msg`** (`app/Core/Console/Hooks/ConventionalCommitMsgHook.php`)
  — valida la **primera línea útil** del mensaje contra
  `tipo(ámbito)!: descripción`, con los once tipos de Conventional Commits. Deja
  pasar lo que escribe git por su cuenta: `Merge …`, `Revert "…"`, `fixup!`,
  `squash!` y `amend!`. Si falla, imprime el formato, la lista de tipos y un
  ejemplo válido. Registrado en `config/git-hooks.php` → `'commit-msg'` (R43).
- **Dos checks nuevos en `kore:arch:check`**: **R49** (cada
  `.agents/skills/{nombre}` tiene su symlink en `.claude/skills/{nombre}`
  apuntando exactamente a `../../.agents/skills/{nombre}`, y en `.claude/skills/`
  no hay nada más: una copia real es deriva) y **R50** (`AGENTS.md` es lo que
  generaría `kore:agents:sync`). El pre-commit los corre y, si fallan, **no
  regenera nada**: nombra el comando. Un hook que escribe deja commiteado algo
  distinto de lo que se revisó.
- **`.github/workflows/release.yml`** — al empujar un tag `v*`: extrae la sección
  del CHANGELOG con `kore:changelog:section`, **falla si no existe** y publica el
  GitHub Release con `softprops/action-gh-release@v2`, nombre `vX.Y.Z` y esa
  sección como cuerpo. Permisos mínimos (`contents: write`). **No se usa
  release-please** a propósito: generaría un CHANGELOG en inglés desde los
  subjects de los commits y chocaría con el que hay, escrito a mano y en español
  porque es la API de actualización de los proyectos derivados.
- **`.github/PULL_REQUEST_TEMPLATE.md`** (qué cambia, por qué, las reglas `R{n}`
  afectadas, y una checklist con `composer ci`, `npm run e2e` si tocó UI, doc en
  el mismo commit, `[Unreleased]`, `AGENTS.md` regenerado y **ninguna válvula
  nueva sin `@owner`**), **`.github/ISSUE_TEMPLATE/`** (`bug_report.md` con «qué
  hace y dónde dice otra cosa» y la salida de `php artisan about --only=kore`,
  `feature_request.md` con la regla de tres delante, y `config.yml` con
  `blank_issues_enabled: false`) y **`.github/CODEOWNERS`** (`* @CesarOvilla` y
  dueño explícito para `docs/architecture/rules.md`, `config/kore-app.php`,
  `.github/` y `app/Core/`: un cambio en el catálogo, en los toggles, en la
  automatización o en el kernel pide review explícito).
- **`docs/patterns/`** — la **regla de tres** (una vez es un caso, dos una
  coincidencia, tres un patrón), el camino de vuelta de un proyecto hijo al padre
  y el formato fijo de cada patrón. Estrena con los dos que ya la cumplen:
  `toggle-provider.md` (`TenancyModuleServiceProvider`,
  `FortifyServiceProvider::configureTwoFactorFeature()`, `BackupServiceProvider`)
  y `test-con-otro-entorno.md` (`TwoFactorToggleTest`, `BackupTest`,
  `ProductionConfigTest`, que convergieron en `withEnvironment()`).
- **`docs/ops/upgrading-from-boilerplate.md`** — la receta para un derivado:
  `git remote add kore`, `git fetch kore --tags`, `git merge vX.Y.Z`, leer la
  nota de migración de **cada versión intermedia**, los archivos que siempre dan
  conflicto y cómo reconciliar cada uno, qué hacer con las migraciones
  publicadas, y cómo verificar después. Cierra con el camino inverso.
- **`docs/modules/docs.md`** y **`compatibility:`** en el frontmatter de los
  cuatro skills propios (parte del estándar Agent Skills; Claude Code lo acepta e
  ignora).
- **Tests nuevos (277 → 391)**: `KoreMcpTest` (15), el módulo Docs (43),
  `AgentsSyncCommandTest` (7), `ChangelogSectionCommandTest` (9), 10 casos más en
  `ArchCheckCommandTest` (73 → 83) y 30 más en `GitHooksTest` (6 → 36).

### Cambiado

- **Los ocho skills dejan de estar duplicados.** `.agents/skills/{nombre}/` es la
  carpeta real —el formato del estándar Agent Skills— y `.claude/skills/{nombre}`
  pasa a ser un **symlink relativo** a `../../.agents/skills/{nombre}`, uno por
  skill (no uno de la carpeta padre: Claude Code sigue los enlaces a nivel de
  skill individual, pero no el del contenedor). El reparto no es arbitrario:
  Codex **no resuelve symlinks**, así que la carpeta real tiene que ser la que él
  lee. Git versiona los enlaces (modo `120000`) (R49).
- **`AGENTS.md` cambia de contenido**: gana la cabecera del
  generador. Ya no es byte a byte igual que `CLAUDE.md`.
- **`config/kore-app.php`** pasa de ocho a nueve claves con `docs.enabled`. Los
  docs que citaban «ocho» dicen nueve (R41). **`.env.e2e`** pierde siete
  variables fantasma que arrastraba desde la v1.0.0 (`TENANCY_MODE`,
  `REVERB_ENABLED`, `OCTANE_*`, `SCOUT_*`, `SENTRY_ENABLED`).
- **`ArchCheckCommand::checkCitedRulesExist()` barre sólo `.agents/skills`**:
  con los symlinks, barrer los dos sets leería cada archivo dos veces.
- **`AppServiceProvider::registerCoreCommands()`** registra `kore:agents:sync` y
  `kore:changelog:section` junto a `ArchCheckCommand` y `PrePushCommand`.
- **`docs/architecture/rules.md`**: rango `R1..R50`; R49 y R50 nuevas en §7;
  **R43** pasa de `Manual · Warning` a `hook commit-msg · Error`; **R42** gana
  `release.yml` / `kore:changelog:section` como enforcement; **R10** declara su
  segunda excepción (el namespace de vistas, que sin rutas no expone nada; lo
  necesita Larastan, que valida cada `view('docs::x')` contra la app que arranca
  en el análisis); el «Índice por herramienta» suma R49 y R50 a
  `kore:arch:check` y dos filas nuevas («Hooks de git» → R43, «GitHub Actions» →
  R42); la tabla de capas suma `commit-msg` y `Release`; y los recuentos del
  final pasan de **40/8** a **44/6** (manuales: R31, R32, R35, R36, R39 y R41).
- **`resources/views/welcome.blade.php`** y el layout público: los enlaces a la
  documentación pasan por `Route::has('docs.index')`: al visor si el toggle está
  encendido, a GitHub si no. `resources/css/app.css` gana `.docs-prose` sobre
  tokens de koreUi (sin instalar `@tailwindcss/typography`).
- **`CLAUDE.md`** (y por tanto `AGENTS.md`): aviso de que `AGENTS.md` es
  generado, línea «AI:» del stack con el MCP `kore` y los symlinks, `Core/Mcp/`
  en el árbol, puntos 12 (R43) y 15 (R49 · R50) en las reglas de oro, la fila
  `commit-msg` y `Release` en las capas, `DOCS_ENABLED` en los toggles, los
  comandos nuevos, dos «NO HACER» más y la lista «Antes de finalizar» con
  `kore:agents:sync` y el hook.
- **Cifras al día** (R41) en `rules.md` y `pipeline.md`: 391 tests, 57 E2E en 15
  archivos, `composer ci` 16 s, pre-push 4 s, pre-commit 0,7 s, commit-msg 0,3 s.
- **Los números de las reglas de mentira de `ArchCheckCommandTest`** (los
  fixtures que declaran `> Escape:`) suben de `R50..R54` a `R80..R84`: R50 ya es
  una regla real.

### Corregido

- **`config/backup.php` no generaba el zip cuando `BACKUP_ARCHIVE_PASSWORD`
  estaba presente y vacía.** `.env.example` trae `BACKUP_ARCHIVE_PASSWORD=`, así
  que después del `composer setup` documentado `env()` devuelve `''` —no `null`—,
  spatie da la encriptación por activada y falla al crear el archivo:
  `backup:run` no dejaba nada y `BackupTest > it leaves the backup archive in the
  clear without a password` se ponía en rojo. En CI pasaba porque allí no hay
  `.env`. Es exactamente el fallo que la v1.3.0 corrigió en `config/logging.php`
  y en `backup.notifications.mail.to`; a esta clave se le escapó. Ahora
  `env('BACKUP_ARCHIVE_PASSWORD') ?: null`. Lo encontraron, por separado, los
  tres agentes que trabajaron esta release.

### Migración desde 1.3.0

Nada de esto rompe una aplicación en marcha: no toca rutas existentes, módulos
ni base de datos. Para traerlo a un derivado:

1. **Skills a symlinks.** Con las dos carpetas idénticas (`diff -r .claude/skills
   .agents/skills` vacío; si no, resuélvelo antes: la que se conserva es
   `.agents/skills`):

   ```bash
   for name in $(ls .agents/skills); do
     rm -rf ".claude/skills/$name"
     ln -s "../../.agents/skills/$name" ".claude/skills/$name"
   done
   git add -A .claude/skills && git ls-files -s .claude/skills   # modo 120000
   ```

   Copia `app/Core/Console/ArchCheckCommand.php` (el check de R49 y el barrido
   de citas) y verifica con `php artisan kore:arch:check --rule=R49`.
2. **`AGENTS.md` generado.** Copia `app/Core/Support/AgentsFile.php`,
   `app/Core/Console/AgentsSyncCommand.php` y su registro en
   `AppServiceProvider::registerCoreCommands()`. Si tu `AGENTS.md` había
   divergido de `CLAUDE.md`, **el comando se queda con `CLAUDE.md` y descarta la
   diferencia**: `diff CLAUDE.md AGENTS.md` antes de correrlo, y lleva a
   `CLAUDE.md` lo que quieras conservar. Después `php artisan kore:agents:sync`.
3. **Hook de `commit-msg`.** Copia
   `app/Core/Console/Hooks/ConventionalCommitMsgHook.php`, regístralo en
   `config/git-hooks.php` → `'commit-msg'` y corre `php artisan
   git-hooks:register` (desde el clon normal: en un git worktree el paquete falla
   con «Git not initialized in this project», porque ahí `.git` es un archivo).
   Sólo mira los commits nuevos: no reescribe nada.
4. **MCP `kore`.** Copia `app/Core/Mcp/` entera y `routes/ai.php` (sin Boost,
   `composer require --dev laravel/mcp`). Añade `"kore": { "command": "php",
   "args": ["artisan", "mcp:start", "kore"] }` a `.mcp.json` y
   `[mcp_servers.kore]` a `.codex/config.toml`. `KoreMcpTest` afirma cosas de
   este repositorio (cuatro módulos, nueve toggles, tres roles): es un inventario,
   ajústalo al tuyo.
5. **Visor `/docs`.** Copia `app/Modules/Docs/`, regístralo en
   `bootstrap/providers.php`, añade el bloque `docs` a `config/kore-app.php`,
   `DOCS_ENABLED=true` a `.env.example` y `.env.e2e`, `DOCS_ENABLED=false`
   forzado en `phpunit.xml` y **`DOCS_ENABLED=false` en el `.env` de
   producción** (en un derivado privado, `docs/` es interno). El `.docs-prose` de
   `resources/css/app.css` y el cambio de `welcome.blade.php` son opcionales.
6. **Release y GitHub.** Copia `app/Core/Console/ChangelogSectionCommand.php` (y
   su registro) y `.github/workflows/release.yml`; si tu `CHANGELOG.md` no usa
   `## [X.Y.Z] - fecha`, adáptalo o el workflow fallará siempre. Copia
   `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/` y
   `.github/CODEOWNERS`, y **cambia `@CesarOvilla` por tu usuario**.
7. **Catálogo y docs.** Añade R49 y R50 a tu `rules.md` (o `composer arch`
   fallará por R40 en cuanto el código las cite), actualiza `R1..R48` →
   `R1..R50`, y si copias `docs/patterns/`, `docs/ops/upgrading-from-boilerplate.md`
   o `docs/modules/docs.md`, enlázalos desde tu `docs/README.md` (R40).

## [1.3.0] - 2026-09-03

«Producción completa». El stack Docker de la v1.2.0 tenía volúmenes persistentes
de MySQL y `storage/` y ninguna copia de ninguno; las cabeceras de seguridad
vivían sólo en el Nginx del contenedor; `APP_DEBUG=true` en producción
arrancaba sin rechistar; los `down()` de las migraciones existían pero nadie los
había ejecutado; y los logs de un contenedor iban a un archivo que `docker
compose logs` no ve. Esta release cierra los cinco huecos, cada uno con su test.

Sin cambios de comportamiento para el usuario final: la suite E2E —45 tests en
12 archivos— pasa sin tocar una línea de vista. La suite Pest pasa de 232 a
**277** tests y el catálogo de `R1..R45` a `R1..R48`, con **40** reglas con
verificador automático (eran 37).

### Añadido

- **Backups con `spatie/laravel-backup` (10.3) detrás del toggle
  `BACKUP_ENABLED`** (default `false`). El provider del paquete está en
  `extra.laravel.dont-discover` —igual que stancl/tenancy— y lo registra
  `app/Providers/BackupServiceProvider.php` sólo cuando el toggle está
  encendido: apagado no existe ni `backup:run`, ni el check de `/health`, ni las
  entradas del scheduler (R10). Era el hueco de ops más grande de la auditoría
  del 2026-09-02.
- **La tríada programada** en `routes/console.php`, dentro de un `if` sobre
  `config('kore-app.backup.enabled')`: `backup:clean` a las 01:00, `backup:run`
  a las 02:00 (`withoutOverlapping`, porque un dump grande puede pasarse de la
  hora) y `backup:monitor` a las 03:00. Se limpia antes de hacer el backup del
  día para que quepa. Todas `onOneServer()`.
- **`config/backup.php` publicado y adaptado.** El nombre del backup y la lista
  de discos se calculan **una sola vez** al principio del archivo (`$name`,
  `$disks` desde `BACKUP_DISKS`) y se reutilizan en `backup.destination.disks` y
  en `monitor_backups`: es lo que garantiza que el monitor vigile el destino
  real y no uno paralelo que se desincroniza. El origen deja fuera `base_path()`
  —el código vive en git— y se queda con `storage/app/public` y
  `storage/app/private`, excluyendo la carpeta de los propios backups para que
  cada zip no se lleve dentro todos los anteriores.
- **Zip cifrado con AES-256** vía `BACKUP_ARCHIVE_PASSWORD`. Sin contraseña el
  zip va en claro, y el doc de despliegue lo marca como obligatorio en
  producción (`openssl rand -base64 32`, guardada fuera del servidor).
- **`BackupsCheck` de spatie/laravel-health** en `/health` y `/health/json`,
  registrado desde `BackupServiceProvider` —no desde `HealthServiceProvider`—
  porque `Health::checks()` acumula (`array_merge`) en vez de sustituir: así el
  check vive pegado al toggle que lo enciende. Vigila el primer disco de
  `BACKUP_DISKS` y la carpeta `BACKUP_NAME` (la carpeta, no un glob: con
  `onDisk()` el check hace `listContents()` y un patrón no listaría nada).
- **`tests/Feature/BackupTest.php`** (16 tests): con el toggle apagado, ni
  provider, ni comandos, ni scheduler, ni check; con el toggle encendido, los
  cuatro comandos, las tres tareas programadas, el check registrado y —lo que
  pedía la auditoría— **que el monitor vigila el mismo destino** (`disks` y
  `name` idénticos, y `BACKUP_DISKS=local,s3` produciendo `['local','s3']` en los
  dos sitios). Dos tests corren `backup:run --only-files` contra un disco falso y
  abren el zip con `ZipArchive::statIndex()` para comprobar que con contraseña
  `encryption_method` **no** es `EM_NONE`, y sin ella sí lo es.
- **`config/security.php` + `App\Http\Middleware\SecurityHeaders`** — las
  cabeceras de seguridad las emite ahora la **aplicación**, en el grupo `web`,
  no el `docker/nginx/nginx.conf`. Cinco cabeceras fijas (`X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`,
  `Cross-Origin-Opener-Policy`), HSTS **sólo sobre HTTPS** —en HTTP el navegador
  la ignora por spec y en local sólo dejaría el dominio clavado en https durante
  un año— y una CSP completa. El middleware **no pisa** una cabecera que la
  respuesta ya traiga, así que un controller puede anular una puntualmente (una
  página pensada para ir dentro de un iframe ajeno). Hasta ahora, cualquier
  despliegue fuera de ese Docker salía sin ninguna cabecera y nada lo decía
  (R46).
- **CSP con estreno en modo informe.** `CSP_REPORT_ONLY=true` por defecto emite
  `Content-Security-Policy-Report-Only`, y `CSP_REPORT_URI` manda las
  violaciones a un recolector (Sentry lo ofrece hecho). Nunca se emiten las dos
  cabeceras a la vez. La receta de despliegue —report-only, revisar informes,
  `CSP_REPORT_ONLY=false`— está en `docs/ops/deployment.md`. Las directivas
  salen del config, así que **un origen nuevo se añade en PHP, no en el
  Nginx**: el cambio viaja en el mismo commit que el código que lo necesita y lo
  cubre un test. `fonts.bunny.net` es el único origen externo real de los
  layouts, y es el único que aparece.
- **`AppServiceProvider::refuseToBootWithDebugInProduction()`** — con
  `APP_ENV=production` y `APP_DEBUG=true`, la aplicación lanza `RuntimeException`
  durante el boot y **no levanta** (R47). La pantalla de error de Laravel en
  modo debug vuelca el `.env` entero —`APP_KEY`, credenciales de base de datos,
  tokens— a quien provoque cualquier excepción, y no da ninguna otra señal hasta
  que alguien ve el volcado.
- **`tests/Feature/MigrationsAreReversibleTest.php`** (2 tests) — R29 exigía un
  `down()` en toda migración, pero el check es textual: comprueba que el método
  existe, no que funcione. Este test hace el ciclo completo `migrate:fresh` →
  `migrate:reset` → `migrate` sobre las 12 migraciones del proyecto, verifica
  que tras el `reset` no queda **ninguna** tabla salvo `migrations`, y que al
  volver a migrar no queda ninguna pendiente. Un segundo test aísla el caso
  frágil —el único `dropColumn()` del boilerplate, el de las columnas 2FA de
  `users`— con un `migrate:rollback --step=N` y comprueba que las columnas
  desaparecen con la tabla todavía en pie. Los `down()` escritos a mano en la
  v1.2.0 para las migraciones publicadas de vendor se ejecutan aquí por primera
  vez: todos pasaron.
- **`tests/Feature/CleanInstallTest.php`** (3 tests) — reproduce la instalación
  que hacen `composer setup` y el entrypoint de Docker, con los seeders reales y
  sin fakes: `migrate:fresh --seed` deja `admin@example.com` con el rol `admin`
  y todos los permisos y las tablas `modules`/`roles`/`permissions` sembradas;
  `db:seed` dos veces seguidas es idempotente; y `E2eSeeder` levanta sus cuatro
  cuentas con su rol y sus permisos directos sobre una base vacía.
- **`tests/Feature/LoggingTest.php`** (5 tests) — blinda la receta de logging de
  producción: que el canal `stderr` escribe en `php://stderr`, que
  `LOG_STDERR_FORMATTER` con un nombre de clase de Monolog llega al handler como
  `JsonFormatter`, que sin la variable Laravel deja el `LineFormatter` (el fallo
  silencioso que deja al agregador sin JSON), que `LOG_LEVEL` llega al handler y
  que `LOG_STACK=stderr` construye un stack de un solo handler.
- **`tests/Feature/SecurityHeadersTest.php`** (14 tests: las cabeceras fijas
  como dataset generado del propio `config/security.php`, report-only vs
  bloqueo, CSP apagada, `report-uri` al final de la política, HSTS sobre https /
  no sobre http / con el toggle apagado, respeto a una cabecera que la respuesta
  ya trae, y `/health/json` intacto) y **`ProductionConfigTest`** (3 tests sobre
  el guard de `APP_DEBUG`, uno de ellos arrancando la aplicación de verdad en
  producción). `PublicPagesTest` suma el caso del `robots.txt`.
- **`withEnvironment()` en `tests/Pest.php`**: arranca la aplicación con otras
  variables de entorno y la restaura al terminar. `Env::getRepository()->set()`
  a secas —el patrón que usaba `TwoFactorToggleTest`— no sirve cuando la
  variable existe en el `.env` del desarrollador: el repositorio de Dotenv es
  inmutable y la recarga del `.env` la vuelve a pisar en el siguiente
  `refreshApplication()`. El helper la saca del repositorio y la escribe como
  «definida desde fuera» (`$_ENV`, `$_SERVER`, `putenv`), que es lo único que
  Dotenv respeta. Lo usan `BackupTest`, `ProductionConfigTest` y
  `TwoFactorToggleTest`, y da el mismo resultado con `.env`, sin él o con la
  variable dentro (comprobado en los tres escenarios).
- **`docker/php/www.conf`** — pool `www` de PHP-FPM dimensionado para un VPS
  pequeño: `pm = dynamic` con `max_children = 20` (RAM para PHP / ~40 MB por
  worker), `max_requests = 500` para cortar fugas de memoria,
  `request_terminate_timeout = 60s`, `clear_env = no` (los workers heredan el
  `env_file`), `catch_workers_output = yes` para que los errores de PHP salgan
  por stderr del contenedor, `expose_php = Off`, y `ping.path = /ping` +
  `pm.status_path = /status`. El `Dockerfile` lo copia como
  `/usr/local/etc/php-fpm.d/zzz-kore.conf`: la imagen `php:8.4-fpm-alpine`
  incluye ese directorio en orden alfabético y trae ya `www.conf` y
  `zz-docker.conf`, así que el prefijo `zzz-` es lo que hace que nuestras
  directivas ganen.
- **Rotación de logs en `docker-compose.prod.yml`** — ancla YAML `x-logging`
  (`json-file`, `max-size: 10m`, `max-file: 5`) aplicada a los **seis**
  servicios. Sin ella el driver `json-file` no rota nada y el log de un
  contenedor crece hasta llenar el disco del VPS.
- **Healthcheck del servicio `nginx`** — `wget -qO- http://127.0.0.1/up`, que
  recorre Nginx → FastCGI → PHP-FPM → Laravel (`/up` es la ruta de salud de
  `bootstrap/app.php`). `nginx:alpine` ya trae el `wget` de busybox.
- **`php artisan about`** muestra `Backup` (enabled/disabled y si el zip va
  cifrado o no).
- **Tres reglas nuevas en el catálogo**: R46 (las cabeceras de seguridad las
  emite la aplicación), R47 (`APP_DEBUG=true` no arranca en producción) y R48
  (producción hace copias de seguridad cifradas y monitorizadas). Las tres las
  verifica Pest. R29 gana un segundo verificador (`MigrationsAreReversibleTest`)
  y §4 · Datos una nota sobre `CleanInstallTest`.
- **Docs**: en `deployment.md`, secciones «Logs», «Healthchecks», «PHP-FPM»,
  «Cabeceras de seguridad y CSP», «Backups» y una **receta de restore completa**
  (extraer el zip AES con `ZipArchive` o `7z` —`unzip` no lo descifra—, quitar
  la línea de *sandbox mode* de `mariadb-dump` 11.4+, importar, devolver
  `storage/app`, verificar); en `observability.md`, «Backups vigilados» y la
  receta de logs a stderr; fila nueva en `toggles.md`; bloques nuevos en
  `.env.example`.

### Cambiado

- **`config/kore-app.php`** pasa de siete a ocho claves con `backup.enabled`.
  Los docs que citaban «siete» (`toggles.md`, `CLAUDE.md`, `AGENTS.md`) dicen
  ocho (R41). Las variables de cabeceras (`SECURITY_HSTS`, `CSP_ENABLED`,
  `CSP_REPORT_ONLY`, `CSP_REPORT_URI`) **no** entran en `kore-app`: no son
  toggles de capacidades sino la configuración de un middleware.
- **`config/database.php`**: la conexión `mysql` gana un bloque `dump`
  (`use_single_transaction`, `timeout`, `add_extra_option`) que lee
  `Spatie\Backup\Tasks\Backup\DbDumperFactory`. `add_extra_option` sale de
  `BACKUP_DUMP_EXTRA_OPTION`, con `--skip-ssl` por defecto: la red interna de
  `docker-compose.prod.yml` va sin TLS y el cliente mariadb 11.x lo exige.
- **`Dockerfile`**: la imagen de runtime instala `mysql-client` (trae
  `mariadb-dump`; sin él `backup:run` fallaba con «The dump process failed» y
  guardaba sólo los archivos) y `fcgi` (trae `cgi-fcgi`, para el healthcheck),
  y copia `docker/php/www.conf`.
- **Healthcheck del servicio `app`**: de `php -r "echo 'OK';"` —que sólo
  demuestra que el binario de PHP existe— a un ping FastCGI real contra
  `127.0.0.1:9000` (`SCRIPT_NAME=/ping … cgi-fcgi -bind -connect 127.0.0.1:9000
  | grep -q pong`). Importa porque `queue`, `scheduler` y `nginx` arrancan con
  `depends_on: condition: service_healthy`: con FPM caído, el check viejo los
  dejaba salir contra una app muerta.
- **`docker/entrypoint.sh`**: la rama `php-fpm` corre `php artisan queue:restart`
  después de los `*:cache`. Deja una marca en la caché (Redis en producción) que
  los workers miran entre job y job: terminan el que tienen entre manos y salen
  limpiamente, sin que un `docker restart` les corte la ejecución por la mitad.
  Con `|| true`, porque en el primer arranque la caché puede no estar lista.
  **No sustituye a recrear el contenedor `queue`** en un despliegue de código
  nuevo: el worker reaparece con la imagen con la que fue creado.
- **`bootstrap/app.php`**: `$middleware->web(append: [SecurityHeaders::class])`.
  Va en el grupo `web` y no global porque las cabeceras protegen lo que
  interpreta un navegador; `/health/json` y las rutas de API devuelven JSON.
- **`docker/nginx/nginx.conf`**: se retiran las líneas `add_header
  Content-Security-Policy …` y `add_header Strict-Transport-Security …` (HSTS la
  emite la app sólo sobre HTTPS, y así `SECURITY_HSTS=false` la apaga de verdad;
  el Nginx interno recibe HTTP plano del proxy). Dos CSP simultáneas no se suman: el navegador
  aplica las dos y gana la intersección, así que la del Nginx haría imposible
  observar en report-only lo que la de la app va a bloquear. Las demás cabeceras
  **se quedan** como defensa en profundidad: Nginx sirve `/build/`, fuentes e
  imágenes sin pasar por PHP. `X-XSS-Protection` sigue sólo ahí (obsoleta; la
  aplicación no la emite).
- **`public/robots.txt`**: pasa de `Disallow:` (permitir todo) a esconder
  `/pulse`, `/health` y `/users`. No es un control de acceso —los tres están
  detrás de sesión, rol o token— sino dejar de publicar el mapa de los paneles
  operativos en el índice de un buscador.
- **`.env.example`**: `LOG_STDERR_FORMATTER` (comentada: presente y vacía
  rompería el canal, ver abajo) y el bloque con la receta de
  logging de producción (`LOG_STACK=stderr`, `JsonFormatter`,
  `LOG_LEVEL=warning`), más los bloques de cabeceras y de backup.
- **`docs/ops/deployment.md`**: `APP_DEBUG=false` documentado como
  **obligatorio** en los valores de producción, porque ahora la aplicación se
  niega a arrancar sin él; los valores de logging; y el apartado de despliegue
  explica qué cubre y qué no cubre el `queue:restart` del entrypoint.
- **`docs/architecture/rules.md`**, `CLAUDE.md`, `AGENTS.md`, `README.md`,
  `docs/README.md`, `working-with-ai.md`, `overview.md`: el rango pasa a
  `R1..R48`; las reglas de oro ganan el punto 14 (R46 · R47 · R48).
- **`docs/quality/pipeline.md`** y la tabla de capas de `rules.md`: cifras al
  día (277 tests; `composer ci` 10 s, pre-push 3 s, pre-commit 0,7 s).

### Corregido

- **`config/logging.php` y `config/backup.php`**: `formatter` y `notifications.mail.to`
  usan `env('X') ?: <default>` en vez del segundo argumento de `env()`. Con la
  clave presente pero vacía en el `.env` (`X=`), `env()` devuelve `''`, no
  `null`: el `LogManager` intentaba resolver la clase `''` y degradaba en
  silencio al emergency logger de `storage/logs/laravel.log`, y las
  notificaciones de backup se enviaban a una dirección vacía. Lo destapó la
  revisión cruzada de la release sobre un `.env` copiado de `.env.example`.
- **`database/seeders/DatabaseSeeder.php` no era idempotente**: `db:seed` dos
  veces seguidas reventaba con `UNIQUE constraint failed: users.email` al
  intentar crear otra vez `admin@example.com`. Pasa a buscar primero al admin
  existente y crearlo sólo si no está. Lo destapó `CleanInstallTest`.
- **`TwoFactorToggleTest > it drops the two-factor routes…` fallaba en cuanto
  el `.env` local definía `AUTH_2FA_ENABLED`** (es decir, tras el `composer
  setup` documentado); en CI pasaba porque allí no hay `.env`. Es el mismo
  mecanismo del punto `withEnvironment()` de arriba; el test lo usa ahora.

### Migración desde 1.2.0

Nada de esto rompe una aplicación en marcha. Para traerlo a un derivado:

1. **Backup.** `composer require spatie/laravel-backup:^10.3` y añade
   `"spatie/laravel-backup"` a `extra.laravel.dont-discover` de `composer.json`
   (si te lo saltas, los comandos `backup:*` existen siempre y el toggle no
   toggle nada). Copia `config/backup.php`,
   `app/Providers/BackupServiceProvider.php` (regístralo en
   `bootstrap/providers.php` después de `HealthServiceProvider`), el bloque
   `backup` de `config/kore-app.php`, el bloque `if` de `routes/console.php`, el
   bloque `dump` de la conexión `mysql` en `config/database.php` y
   `tests/Feature/BackupTest.php`. En el `Dockerfile`, `mysql-client` (o
   `default-mysql-client` si tu imagen es Debian). Variables nuevas:
   `BACKUP_ENABLED`, `BACKUP_DISKS`, `BACKUP_ARCHIVE_PASSWORD`,
   `BACKUP_NOTIFICATION_MAIL` y las opcionales `BACKUP_NAME`,
   `BACKUP_MAX_AGE_DAYS`, `BACKUP_DUMP_EXTRA_OPTION`. En producción **genera la
   contraseña del archivo**. Para S3, `composer require
   league/flysystem-aws-s3-v3` y deja `local` como primer disco: es el que
   `BackupsCheck` abre al arrancar. **Ensaya un restore en staging** con la
   receta de `deployment.md` antes de necesitarlo.
2. **Cabeceras.** Copia `config/security.php` y
   `app/Http/Middleware/SecurityHeaders.php`, añade
   `$middleware->web(append: [SecurityHeaders::class]);` en `bootstrap/app.php`
   y **quita la CSP de tu servidor web** (`add_header Content-Security-Policy`
   en Nginx, `Header set` en Apache): si la dejas, el navegador aplica la
   intersección y el modo report-only deja de informar de lo que pasaría. Las
   demás cabeceras pueden quedar duplicadas con el mismo valor. Revisa
   `csp.directives`: si tu aplicación carga scripts, tipografías o imágenes de
   otro origen, añádelos **antes** de pasar a bloqueo. Variables nuevas:
   `SECURITY_HSTS`, `CSP_ENABLED`, `CSP_REPORT_ONLY`, `CSP_REPORT_URI`. Y
   `public/robots.txt`.
3. **`APP_DEBUG`.** Si tu `.env` de producción tiene `APP_DEBUG=true`, **la
   aplicación ya no arrancará**. Es intencionado; ponlo en `false` y vuelve a
   cachear la config.
4. **Docker.** Copia `docker/php/www.conf` (ajusta `pm.max_children` a tu VPS) y
   las dos líneas del `Dockerfile` (`fcgi` en el `apk add` y el `COPY … 
   zzz-kore.conf`; el nombre tiene que ordenarse después de `zz-docker.conf`).
   En `docker-compose.prod.yml`, el ancla `x-logging`, un `logging:
   *default-logging` por servicio, el healthcheck de `nginx` y el de `app`
   (valida con `docker compose -f docker-compose.prod.yml config --quiet`). En
   `docker/entrypoint.sh`, el `queue:restart` después de los `*:cache`
   (`sh -n docker/entrypoint.sh`). En el `.env` de producción,
   `LOG_STACK=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`,
   `LOG_LEVEL=warning`.
5. **Tests.** Copia `MigrationsAreReversibleTest`, `CleanInstallTest`,
   `LoggingTest`, `SecurityHeadersTest` y `ProductionConfigTest`, y los helpers
   `releaseRefreshDatabaseTransaction()`, `withEnvironment()` y
   `writeRawEnvVariable()` de `tests/Pest.php`. Si tu proyecto añadió módulos,
   revisa `MIGRATION_SMOKE_TABLES` (una tabla por origen) y los conteos de
   `CleanInstallTest` (`Module::count()` y `SpatieRole::count()` esperan 3 y 3).
   Si tu `DatabaseSeeder` sigue creando el admin con `User::factory()->create()`,
   aplícale el mismo arreglo o el test de idempotencia fallará, que es justo lo
   que tiene que hacer.
6. **Catálogo.** Añade R46, R47 y R48 a tu `rules.md` (o `composer arch`
   fallará por R40 al ver citadas reglas que no existen), y actualiza `R1..R45`
   → `R1..R48` donde lo cites.

## [1.2.0] - 2026-09-03

«Disciplina verificable». Las reglas del boilerplate eran prosa en `CLAUDE.md` y
su único verificador eran 16 arch tests. Ahora son un catálogo numerado de 45
reglas (`R1..R45`), cada una con su enunciado, quién la verifica, con qué
comando, con qué severidad, la válvula de escape que admite y **la cicatriz real
que la originó** —sacada del CHANGELOG y de la auditoría, no inventada—. De las
45, **37 tienen verificador automático** que falla el build; las 8 restantes son
manuales y lo dicen.

Las reglas que no se podían verificar con lo que había ahora se verifican con
tres herramientas nuevas: PHPat (grafo de dependencias),
`spaze/phpstan-disallowed-calls` (llamadas prohibidas por ruta) y
`php artisan kore:arch:check` (checks textuales: `#[Locked]`, `authorize()`,
`down()`, válvulas caducadas, toggles fantasma, docs sin enlazar). Las tres
caben en el pipeline sin notarse: el pre-commit tarda 0,4 s, el pre-push 2,5 y
`composer ci` completo, 6.

Sin cambios de comportamiento para el usuario final: la suite E2E —45 tests en
12 archivos— pasa sin tocar una línea de vista.

### Añadido

- **`docs/architecture/rules.md`** — catálogo `R1..R45` repartido en siete
  secciones (arquitectura, código, seguridad, datos, UI/i18n, tests, docs y
  versionado). Cada regla lleva `> Enforcement: herramienta · comando ·
  severidad`, `> Escape:`, un **Por qué** y una **Cicatriz** con su versión. Al
  final: el índice por herramienta, la gramática de las válvulas de escape y la
  tabla de capas de verificación con tiempos medidos.
- **PHPat** (`phpat/phpat` 0.12, dev) registrado como servicio de PHPStan con el
  tag `phpat.test`. `tests/Arch/PhpatArchitecture.php` implementa R1, R4, R5,
  R6, R7, R8 y R19 sobre el grafo de dependencias real, que es lo que Pest arch
  no ve. La regla de imports cruzados se **genera para cada par de módulos** a
  partir de `glob(app/Modules/*)`, así que un módulo nuevo queda cubierto sin
  tocar el archivo.
- **`spaze/phpstan-disallowed-calls`** (4.x, dev) con `phpstan-disallowed.neon`:
  una entrada por regla —R17, R18, R19, R20, R21, R22 y R27—, cada una con
  `message:` citando el número y `allowIn:` / `allowExceptIn:` diciendo dónde sí
  es correcto. Comprobadas una a una introduciendo una violación temporal.
- **`php artisan kore:arch:check`** (`app/Core/Console/ArchCheckCommand.php`) con
  `--files`, `--rule` y `--root`. Diez checks textuales: R11 (toggles que nadie
  lee), R23 (`authorize()` en los métodos de escritura de Livewire), R24
  (`#[Locked]`), R29 (`down()`), R30 (Eloquent en Blade), R37 (`data-testid`),
  R38 (`waitForTimeout`), R40 (índice de docs y citas de reglas), R44 (gramática
  y caducidad de las válvulas) y R45 (baseline con fecha). Salida
  `R{n} archivo:línea mensaje`, exit 1 si hay algo, ~0,2 s. Script `composer arch`,
  dentro de `composer ci` y de CI.
- **Válvulas de escape con gramática fija y caducidad**:
  `// arch-exception: R12 · razón · @owner · 2026-12-31` (temporal; el build
  falla cuando vence) y `// arch-accepted: R20 · razón · @owner` (decisión
  aceptada). Documentadas en `rules.md`, `CLAUDE.md` y `AGENTS.md`, con la regla
  R44: **el agente nunca escribe una válvula por su cuenta**; si la necesita, se
  detiene y pregunta, porque el `@owner` lo firma una persona.
  Las dos formas **no son intercambiables**: cada regla declara en su
  `> Escape:` cuál admite, y el check lo verifica. Una `arch-exception` sobre una
  regla que sólo acepta `arch-accepted` falla el build y, además, no exime nada
  —una válvula de la forma equivocada no silencia su check—.
- **Hooks de git propios** en `app/Core/Console/Hooks/`:
  `ArchCheckPreCommitHook` (pasa los archivos staged al comando) y `PrePushHook`
  (PHPStan + `pest --parallel`, parando en el primero que falle). Registrados en
  `config/git-hooks.php`.
- **Arch tests nuevos** (16 → 21): la lista cerrada de carpetas de módulo (R3),
  `Events` `final readonly`, `Rules` `final` que implementan `ValidationRule`,
  DTOs que no dependen de `Illuminate\Http` y **DTOs con todas sus propiedades
  `readonly`** (R8). Este último no es un `arch()` sino un `test()` con
  reflexión: `toBeReadonly()` mira la clase readonly de PHP 8.2 y estos DTOs son
  `final class` con propiedades promovidas `public readonly`. Todos citan su
  `R{n}`.
- **Tests nuevos** (149 → 232): `ArchCheckCommandTest` (73 casos con árboles de
  fixtures que violan y cumplen cada check, vía `--root`) y `GitHooksTest`
  (5 casos sobre la decisión de cada hook, con `Process::fake()`).
- `down()` en tres migraciones publicadas de vendor que no lo tenían
  (`one_time_passwords`, `activity_log` y las tablas de `spatie/laravel-health`).
  Las encontró la propia regla R29 al escribirla.

### Cambiado

- **`phpstan.neon`** carga ahora cuatro cosas: Larastan, la extensión de PHPat,
  la de disallowed-calls y `./phpstan-disallowed.neon`. `paths` incluye
  `tests/Arch/PhpatArchitecture.php` (PHPStan tiene que analizarlo para poder
  reflejarlo); `tests/Arch/ArchitectureTest.php` sigue fuera, porque su sintaxis
  funcional de Pest no la entiende.
- **`CLAUDE.md` y `AGENTS.md`**: las «reglas de oro» pasan a ser un resumen de 13
  líneas que cita `R{n}` y enlaza el catálogo. Se añaden las secciones «Válvulas
  de escape» y «Capas de verificación», y `composer arch` a los comandos. No se
  ha borrado ninguna regla: todas están integradas con su número.
- **`docs/README.md`** es ahora un índice con enlaces reales a cada doc, y R40
  falla si aparece un `.md` que no esté listado.
- **`docs/architecture/module-pattern.md`**: la lista de carpetas de un módulo es
  cerrada, con una tabla que dice qué contiene cada una y quién la vigila, y el
  procedimiento para pedir una carpeta nueva.
- **`docs/quality/pipeline.md`**: PHPat, disallowed-calls, `kore:arch:check`, los
  hooks nuevos, la tabla de capas con tiempos medidos y las cifras al día
  (232 tests).
- **Skills** (`.claude/skills/` y su copia idéntica en `.agents/skills/`):
  `module-scaffold` documenta la lista cerrada y `composer arch`;
  `kore-action-create` cita R1, R2, R8, R19, R20 y R21; `kore-livewire-create`
  cita R23, R24 y R30; `kore-e2e-test` cita R36, R37, R38 y R39.
- **`app/Providers/AppServiceProvider.php`**: nuevo `registerCoreCommands()`, que
  registra `ArchCheckCommand` cuando la app corre en consola. Laravel sólo
  autodescubre `app/Console/Commands`, carpeta que el layout modular no usa: los
  comandos de dominio los registra el provider de su módulo y los transversales
  de `App\Core\Console` se registran aquí. Sin esto, `php artisan kore:arch:check`
  no existe y `composer arch` falla con "command not found".
- **`composer.json`**: script `arch` (`@php artisan kore:arch:check`), añadido
  también a la cadena `ci` entre `analyse` y `refactor:test`; y las dos
  dependencias de desarrollo nuevas (`phpat/phpat`,
  `spaze/phpstan-disallowed-calls`).
- **`.github/workflows/ci.yml`**: paso `php artisan kore:arch:check` entre
  PHPStan y Rector.
- **`phpunit.xml`**: `<ini name="memory_limit" value="512M"/>`. `PulseAccessTest`
  renderiza el bundle JS de Pulse y con el `memory_limit` de 128M de algunas
  instalaciones de PHP la suite reventaba por memoria, no por el test.
- **`README.md`**: `rules.md` encabeza la lista de documentación, el quality
  stack menciona PHPat, disallowed-calls y `kore:arch:check`, y `composer arch`
  entra en los comandos útiles.
- El preset `laravel()` de los arch tests ignora también `App\Core\Console`, por
  la misma razón que ya ignoraba `App\Core\Enums`: el preset exige que sólo
  `App\Console\Commands` extienda `Command`, y el layout modular no usa esa
  carpeta.

### Corregido

- **`config/logging.php` y `config/backup.php`**: `formatter` y `notifications.mail.to`
  usan `env('X') ?: <default>` en vez del segundo argumento de `env()`. Con la
  clave presente pero vacía en el `.env` (`X=`), `env()` devuelve `''`, no
  `null`: el `LogManager` intentaba resolver la clase `''` y degradaba en
  silencio al emergency logger de `storage/logs/laravel.log`, y las
  notificaciones de backup se enviaban a una dirección vacía. Lo destapó la
  revisión cruzada de la release sobre un `.env` copiado de `.env.example`.
- `git push` moría con `No arguments expected for "git-hooks:pre-push"
  command, got "origin"`: el script que instala `igorsgm/laravel-git-hooks`
  reenvía los argumentos de git (`remote`, `url`) y su comando no los declara.
  `App\Core\Console\Hooks\PrePushCommand` reemplaza al comando del paquete
  con la firma correcta, de modo que la capa 2 (PHPStan + Pest) por fin corre
  en cada push.
- Pest lanzado desde un hook heredaba el `.env` del desarrollador
  (`APP_ENV=local`) porque los `<env>` de `phpunit.xml` no pisan variables ya
  presentes: ahora llevan `force="true"` y el hook fija `APP_ENV=testing`.

### Eliminado

- Dos `.gitkeep` que ya no guardaban ninguna carpeta vacía:
  `app/Modules/.gitkeep` (hay tres módulos desde hace dos releases) y
  `app/Core/Contracts/.gitkeep` (hay un contrato desde la v1.1.0). Los de
  `Core/Concerns/`, `Core/Support/` y `Tenancy/Database/Migrations/` se quedan:
  esas carpetas siguen vacías a propósito.

### Nota de migración (proyectos derivados)

1. **Dos dependencias de desarrollo nuevas.** `composer update` las trae con la
   release; si aplicas los archivos a mano,
   `composer require --dev phpat/phpat spaze/phpstan-disallowed-calls`.
2. **`phpstan.neon` cambia de forma.** Si el tuyo está personalizado, copia los
   cuatro `includes:`, el bloque `phpat:` de `parameters:`, la entrada de
   `paths:` y el bloque `services:` con el tag `phpat.test`. Sin el `services:`,
   PHPat no encuentra ninguna regla y pasa en verde sin verificar nada.
3. **Copia `docs/architecture/rules.md` antes que nada.** Es el catálogo, pero
   también es **entrada del programa**: `kore:arch:check` saca de ahí la lista de
   reglas conocidas leyendo las cabeceras `### R{n} · …`. Sin el archivo,
   `knownRules()` devuelve `[]`, y entonces **toda** válvula de escape del
   repositorio falla con «cita R…, que no existe» y toda `R{n}` citada desde el
   código también. Si adaptas el catálogo a tu proyecto, conserva el formato de
   las cabeceras y no borres una regla que sigas citando.
4. **Registra el comando.** `ArchCheckCommand` vive en `App\Core\Console`, que
   Laravel **no** autodescubre (sólo mira `app/Console/Commands`). Copia
   `registerCoreCommands()` de `app/Providers/AppServiceProvider.php` y llámalo
   desde `boot()`, o registra la clase donde te encaje. Comprueba que aparece:
   `php artisan list | grep kore:arch`.
5. **Añade el script `arch` a `composer.json`** (`"arch": ["@php artisan
   kore:arch:check"]`) y mételo en la cadena `ci`, entre `@analyse` y
   `@refactor:test`. Si no entra en `ci`, el check existe pero nadie lo corre.
6. **Añade el paso al workflow.** En `.github/workflows/ci.yml`, un
   `- name: Kore arch check` con `run: php artisan kore:arch:check` entre
   PHPStan y Rector. Los hooks locales cubren el pre-commit y el pre-push, pero
   quien mergea desde la web sólo pasa por CI.
7. **La primera ejecución va a encontrar violaciones.** Es lo esperado: son
   reglas que hasta ahora nadie comprobaba. El orden recomendado es
   `composer arch` primero (checks baratos y de arreglo obvio) y después
   `composer analyse`. Para lo que no puedas arreglar hoy, usa una válvula **con
   fecha**:
   `// arch-exception: R23 · pendiente de refactor del panel · @tu-usuario · 2026-12-31`.
   No uses `arch-accepted` para aplazar: eso es para decisiones ya tomadas.
8. **Los hooks nuevos se instalan solos** con `composer install`
   (`post-autoload-dump` → `git-hooks:register`). Si mantienes tu propio
   `config/git-hooks.php`, añade `ArchCheckPreCommitHook` a `pre-commit` y
   `PrePushHook` a `pre-push`, o quédate sólo con Pint si prefieres que el push
   no analice.
9. **Si tu módulo tiene carpetas fuera de la lista de R3** (`Services/`,
   `Repositories/`, `Transformers/`...), el arch test fallará. Muévelas a
   `Actions/`, `Support/` o `Data/`, o amplía la lista `$allowed` de
   `tests/Arch/ArchitectureTest.php` **y** documéntalo en tu
   `module-pattern.md`: la lista es una decisión de arquitectura, no un detalle
   de configuración.
10. **Si añades un baseline de PHPStan**, su primera línea tiene que ser
    `# arch-baseline: vence YYYY-MM-DD` o `composer arch` lo rechaza (R45).

## [1.1.0] - 2026-09-02

«El boilerplate se demuestra a sí mismo». La v1.0.0 cerró la brecha entre lo que
los docs prometían y lo que el código *hacía*; ésta la cierra en lo que el
código *es*. El módulo Users —el CRUD de referencia— ahora cumple de verdad las
tres reglas de oro que CLAUDE.md predicaba y que él mismo incumplía: Action
Pattern, DTOs y cero imports cruzados entre módulos. Los arch tests que estaban
comentados con un `TODO v1.1` pasan a fallar el build.

Sin cambios visibles para el usuario final: la suite E2E (45 tests en 12
archivos) pasa sin tocar un solo texto.

### Seguridad

- **Escalada de privilegios al asignar roles y permisos (alta).** Cualquiera con
  `users.create` + `users.edit` podía crear una cuenta con **cualquier** rol y
  **cualquier** permiso del sistema —incluidos los que él mismo no tenía— y
  entrar con ella. Dos reglas nuevas en `app/Modules/Users/Rules/` lo cierran:
  `GrantablePermission` (sólo concedes permisos que tienes) y `GrantableRole`
  (sólo asignas un rol si posees todos sus permisos, medido en permisos y no en
  nombres de rol, para que un rol nuevo quede cubierto solo). El superadmin las
  salta; el actor se pasa por constructor, así que dentro de la regla no se lee
  `auth()`. Cubierto por `PrivilegeEscalationTest`.
  - Limitación conocida: la matriz de permisos de la vista sigue mostrando todos
    los permisos aunque el actor no pueda concederlos. La validación los
    rechaza nombrando el permiso; filtrarlos también en el cliente queda
    pendiente.

### Añadido

- **i18n por módulo con español como idioma fuente**: `APP_LOCALE=es`,
  fallback `en`, faker `es_ES`. `lang/es/{auth,pagination,passwords,validation}.php`
  traducidos; `en.json` en `app/Modules/{Modulo}/Resources/lang/` cargado por
  cada provider, `lang/en.json` compartido y `lang/es.json` para literales
  ingleses de Fortify y correos del framework; español para el correo del
  magic link (`lang/vendor/one-time-passwords/es`). `TranslationsTest` falla
  listando cada `__()` sin traducción. `phpunit.xml` fija el locale para que
  la suite no dependa del `.env` local. Guía: `docs/guides/i18n.md`.
- **Action Pattern real en Users**: `UserCreateAction`, `UserUpdateAction` y
  `UserDeleteAction` (`final`, extienden `App\Core\Actions\Action`, un único
  `handle()`), con `UserData` como DTO y los eventos `UserCreated`,
  `UserUpdated` y `UserDeleted` (`final readonly`) como canal para otros
  módulos. Ninguna Action lee `auth()`, `request()` ni `session()`: sirven igual
  desde un job o un comando. Tests: una clase por Action.
- **`App\Core\Enums\SystemRole`** (`Superadmin` / `Admin` / `User`) — el valor de
  los roles pasa a Core, donde cualquier módulo puede mirarlo.
- **`App\Core\Contracts\AuthorizationCatalog`** + DTOs
  `App\Core\Data\Authorization\{RoleOptionData, PermissionOptionData,
  PermissionModuleData}`, implementado por
  `App\Modules\Auth\Support\AuthorizationCatalog` y bindeado en
  `AuthModuleServiceProvider::register()`. Es la frontera que permite a Users
  dejar de importar `Auth\Models\{Role, Module}`.
- **`App\Modules\Auth\Actions\AuthUserRegisterAction`** + `RegisterData`: el
  registro público también es un caso de uso, y el stub de Fortify sólo valida y
  delega.
- **Dashboard como componente Livewire** (`Auth\Http\Livewire\Dashboard` +
  `DashboardStatData`). La blade hacía `User::count()`, `Permission::count()` y
  `Module::where(...)->count()` dentro de un `@php`; ahora la ruta es
  `Route::get('/dashboard', Dashboard::class)` y las cifras llegan como DTOs.
  Mismo HTML, mismos textos.
- **Factories por módulo**: `AppServiceProvider::configureFactories()` mapea
  `App\Modules\{X}\Models\{Y}` → `App\Modules\{X}\Database\Factories\{Y}Factory`,
  y `Role` y `Module` estrenan `HasFactory` + `RoleFactory` / `ModuleFactory`.
  Si un modelo no tiene factory, el resolver dice dónde la buscó en vez del
  "Class not found" de PHP.
- **Arch tests nuevos** (16 en total, antes 9): imports cruzados entre módulos en
  ambos sentidos (ignorando `Tests/`), `App\Core` no depende de `App\Modules`,
  los `Core\Contracts` son interfaces, los DTOs son `final` y extienden
  `Core\Data\Data`, y las Actions extienden `Core\Actions\Action`.
- Tests: 100 → 149 Pest (los E2E sin cambios: 45 tests en 12 archivos).

### Cambiado

- **`UserForm` ya no persiste**: `store()` desaparece y en su lugar hay
  `toData(): UserData`. `FormComponent::save()` hace
  autorizar → validar → DTO → Action → toast → redirect, con las Actions por
  inyección de método. `TableUsers::confirmDelete()` delega en
  `UserDeleteAction`.
  - Ahí sí se resuelve con `resolve(...)` y no por inyección: el diálogo de
    confirmación de koreUi invoca el método con
    `$this->{$method}(...$params)`, sin pasar por el contenedor. Un parámetro
    tipado de más sólo reventaría en el navegador, no en los tests.
- **Los stubs de Fortify se mudan** de `App\Modules\Auth\Actions\Fortify\` a
  `App\Modules\Auth\Fortify\`: son adaptadores del paquete (el nombre y la
  firma los fija Fortify), no casos de uso, y ensuciaban la regla de Actions con
  una excepción permanente. Los nombres de clase no cambian.
- `Role::SUPERADMIN`, `ADMIN` y `USER` **siguen existiendo**, pero ahora se
  definen desde `SystemRole` (`= SystemRole::Admin->value`), igual que
  `allRoles()` y `assignableNames()`.
- `UserPolicy` y `TableUsers` comparan contra `SystemRole::Superadmin->value`;
  las opciones del select de rol y la matriz de permisos salen del
  `AuthorizationCatalog` (serializadas con `->toArray()` a la misma estructura
  de antes, así que las vistas no cambian).
- El preset `laravel()` de los arch tests se aplica ignorando también
  `App\Core\Enums` (el preset exige que sólo `App\Enums` contenga enums, cosa
  que no encaja con el layout modular).
- Documentación al día con el código: `docs/guides/crud.md` reescrita alrededor
  de Form + Data + Actions + Events, `docs/modules/users.md`,
  `docs/modules/auth.md`, `docs/architecture/module-pattern.md`,
  `docs/architecture/authorization.md` y `docs/quality/pipeline.md`. Los skills
  (`kore-action-create`, `module-scaffold`, `kore-livewire-create`) reflejan el
  patrón real, y su copia en `.agents/skills/` es idéntica.

### Nota de migración (proyectos derivados)

1. **Namespace de Fortify**: cambia `App\Modules\Auth\Actions\Fortify\*` por
   `App\Modules\Auth\Fortify\*` (los nombres de clase son los mismos). Si
   personalizaste `FortifyServiceProvider`, revisa sus `use`.
2. **`UserForm::store()` ya no existe.** Si lo llamabas desde tu código, usa
   `UserCreateAction` / `UserUpdateAction` con `UserForm::toData()`. Si añadiste
   campos al formulario, muévelos de `store()` a la Action correspondiente y al
   DTO.
3. **`Role::` sigue igual**: las constantes y `allRoles()` / `assignableNames()`
   no cambian de firma. Si comparas roles desde otro módulo, migra a
   `SystemRole::Superadmin->value` para no romper el arch test de imports
   cruzados.
4. **Formularios que asignan roles o permisos**: las reglas anti-escalada
   aplican al `UserForm`. Si tu app da de alta usuarios desde un actor con
   permisos limitados, revisa que ese actor tenga los permisos que concede (o
   dale el rol superadmin al proceso).
5. **Si tenías una factory de un modelo de módulo en `database/factories/`**,
   muévela a `app/Modules/{X}/Database/Factories/` o el resolver no la
   encontrará.

## [1.0.0] - 2026-09-02

Primera versión etiquetada. Cierra la brecha entre lo que la documentación
prometía y lo que el código hacía: se tapan dos escaladas de privilegios en el
módulo Users, se conecta la observabilidad que estaba instalada pero muerta, y
se borra todo lo que era decorativo (toggles que nadie leía, restos de
scaffold, cifras inventadas en los docs).

### Seguridad

- **Escalada de privilegios en Users (crítica).** `UserForm::$id` era `public`
  sin `#[Locked]` y `FormComponent::save()` no llamaba a `authorize()`. Un
  cliente con `users.create` podía fijar `form.id` por `/livewire/update` y
  sobrescribir email, password, rol y permisos de cualquier usuario. Ahora
  `$id` va con `#[Locked]` y `save()` / `mount()` autorizan contra la
  `UserPolicy`.
- **Borrado sin autorización (crítica).** `TableUsers::confirmDelete()` sólo
  comprobaba que no fueras tú mismo; el bloqueo real estaba en el `hidden()`
  del RowAction, que es cliente. Ahora llama a `authorize()`.
- Se elimina `Model::unguard()` global. `UserForm` resuelve el modelo
  explícitamente en vez de `updateOrCreate(['id' => ...])`.
- `throttle:api` en el grupo `api` + `RateLimiter::for('api')`. Laravel 12 no
  trae ese limiter por defecto y sin él `throttle:api` degradaba a 0 intentos.
- `trustProxies` en `bootstrap/app.php`: detrás del Nginx del contenedor
  `$request->ip()` era siempre la IP interna, así que los limiters por IP, los
  logs y Sentry perdían la IP real.
- Magic link: rate limit de envío (5 / 5 min por email + IP) y respuesta
  genérica para no permitir enumeración de usuarios.
- `PULSE_ENABLED` con default `false` de verdad, y gates `viewPulse` /
  `viewHealth` restringidos al rol superadmin.
- `composer audit` bloqueante en CI.

### Añadido

- **Suite E2E con Playwright** (`tests/e2e/`, `playwright.config.ts`):
  45 tests en 12 archivos (11 de spec + `auth.setup.ts`, que hace el login por
  rol) sobre landing, auth (login, registro, reset, magic link con lectura
  del código real desde el log, rutas protegidas) y Users (listado, alta,
  edición, borrado, permisos por rol). Entorno aislado con `.env.e2e`,
  `database/e2e.sqlite` y `E2eSeeder`; fixtures por rol; page objects;
  workflow `e2e.yml`; doc `docs/quality/e2e.md`; skill `kore-e2e-test`;
  scripts `npm run e2e*` y `composer e2e`.
- **Observabilidad conectada de punta a punta**: `Integration::handles()` de
  Sentry en `withExceptions()` y canal de log `sentry`; rutas `/health` (HTML,
  sesión + gate) y `/health/json` (token `HEALTH_SECRET_TOKEN`); scheduler real
  en `routes/console.php` con `health:check`, heartbeat y prunes de queue /
  sanctum / activitylog.
- `LogsActivity` en `User` y `Role` como ejemplo vivo de spatie/laravel-activitylog.
- Config y migración de Pennant publicadas (`Feature::active()` reventaba sin
  la tabla `features`).
- **Arch tests reales** en `tests/Arch/ArchitectureTest.php`: presets `php()`,
  `security()` y `laravel()` (este último ignorando `App\Modules`, cuyo layout
  modular no encaja con el preset), `strict_types` en todo `App`, prohibición
  de `dd`/`dump`/`var_dump`/`ray`/`env()` fuera de config, y convenciones de
  nombre y `final` para Actions, Policies y Providers de módulo. README y
  CLAUDE.md prometían arch tests desde el día uno y no había ninguno.
- `.github/dependabot.yml` con composer, npm y github-actions semanales,
  agrupando minor + patch en un PR por ecosistema.
- Job `assets` en CI (`npm ci && npm run build`): los tests corren con
  `withoutVite()`, así que un build roto pasaba verde.
- Este `CHANGELOG.md`.
- Script `composer e2e`, que delega en `npm run e2e`.
- Test del toggle `AUTH_2FA_ENABLED` (`TwoFactorToggleTest`), incluyendo el
  arranque completo de la aplicación con el toggle apagado.
- Los tres skills propios (`module-scaffold`, `kore-action-create`,
  `kore-livewire-create`) también en `.agents/skills/`, que es lo que README y
  `docs/ai/working-with-ai.md` afirmaban desde antes.
- `.gitkeep` en `app/Modules/Auth/Data/` y `app/Modules/Tenancy/Database/Migrations/`,
  y guarda `is_dir()` en `TenancyModuleServiceProvider` para que un clon fresco
  no reviente al migrar.

### Cambiado

- **`AUTH_2FA_ENABLED` es un toggle de verdad.** `config/fortify.php` leía
  `env()` directamente; ahora la feature `twoFactorAuthentication` la añade o la
  quita `FortifyServiceProvider::register()` según
  `config('kore-app.auth.two_factor')`. Un config no puede leer otro (se cargan
  en orden alfabético y `fortify` va antes que `kore-app`), y el `register()` de
  los providers corre antes del `boot()` en el que Fortify publica sus rutas.
- `artisan about` deja de mostrar Reverb (cosmético, el paquete no está
  instalado) y muestra 2FA, magic links, social login, Pulse y si Sentry tiene
  DSN.
- Documentación sincronizada con el código: cifras de tests reales, lista real
  de archivos de test, `bootstrap/providers.php` con `UsersModuleServiceProvider`,
  colas por driver `database` en vez de "Redis + Horizon" (Horizon no está
  instalado) y el enlace a koreUi apuntando a su repositorio.
- Enlaces del landing y del layout público: `/docs` daba 404 y el botón de
  GitHub era un placeholder; ahora apuntan al repositorio y a `docs/` en GitHub.
- Dependencias actualizadas (sólo minors y patches): laravel/framework
  12.68.0 → 12.69.1, laravel/fortify 1.36.2 → 1.39.0, laravel/pulse 1.7.3 →
  1.8.1, laravel/pennant 1.23.0 → 1.26.0, laravel/pint 1.29.1 → 1.30.5,
  laravel/boost 2.4.6 → 2.7.0, laravel/socialite 5.27.0 → 5.31.0,
  laravel/sanctum 4.3.1 → 4.3.3, laravel/sail 1.58.0 → 1.67.0, laravel/pail
  1.2.6 → 1.2.7, livewire/livewire 4.4.2 → 4.4.3, larastan/larastan 3.9.6 →
  3.11.0, rector/rector 2.4.2 → 2.6.6, driftingly/rector-laravel 2.3.0 →
  2.6.2, sentry/sentry-laravel 4.25.0 → 4.27.0, spatie/laravel-data 4.22.1 →
  4.23.0, spatie/laravel-health 1.39.2 → 1.40.2,
  spatie/laravel-one-time-passwords 1.1.0 → 1.1.2, spatie/laravel-permission
  7.4.1 → 7.4.2, stancl/tenancy 3.10.0 → 3.10.1, pestphp/pest 3.8.6 → 3.8.7,
  phpunit/phpunit 11.5.50 → 11.5.56, mockery/mockery 1.6.12 → 1.6.15,
  nunomaduro/collision 8.9.4 → 8.9.5.
- `app()` → `resolve()` en tres tests (`ApiRateLimitTest`, `HealthTest`,
  `SentryIntegrationTest`) y `(string) request()->ip()` → `request()->ip()` en
  `MagicLink`: lo piden `AppToResolveRector` y `RemoveConcatAutocastRector`,
  reglas nuevas de rector-laravel 2.6.
- `.codex/config.toml` sin la ruta absoluta de la máquina del autor y con el
  servidor MCP de kore-ui, la misma URL que declara `.mcp.json`.

### Corregido

- **`config/logging.php` y `config/backup.php`**: `formatter` y `notifications.mail.to`
  usan `env('X') ?: <default>` en vez del segundo argumento de `env()`. Con la
  clave presente pero vacía en el `.env` (`X=`), `env()` devuelve `''`, no
  `null`: el `LogManager` intentaba resolver la clase `''` y degradaba en
  silencio al emergency logger de `storage/logs/laravel.log`, y las
  notificaciones de backup se enviaban a una dirección vacía. Lo destapó la
  revisión cruzada de la release sobre un `.env` copiado de `.env.example`.
- Borrar un usuario desde la acción de fila de la tabla no hacía nada: koreUi
  2.2 arma el diálogo de `RowAction::confirm()` en el cliente pero no autoriza
  el método en `$koreConfirmable`, así que el listener lo descartaba.
  `TableUsers::hydrate()` lo registra como workaround hasta que koreUi lo
  resuelva.
- `HEALTH_OAUTH_TOKEN` en `.env.example` no coincidía con el
  `HEALTH_SECRET_TOKEN` que lee `config/health.php`.
- `UserForm` citaba `docs/guides/crud/livewire-form.md`, que no existe.
- `ModulesSeeder` decía que el `Gate::before` del superadmin está en
  `AppServiceProvider`; está en `AuthModuleServiceProvider`.
- El landing anunciaba "32 Tests Pest" hardcodeado.

### Eliminado

- **Toggles fantasma de `config/kore-app.php`**: `reverb`, `octane`, `search`,
  el bloque `observability` y la clave `tenancy.mode`. Ninguno lo leía nadie y
  los paquetes correspondientes no están instalados. Reverb, Octane y Scout
  pasan a ser módulos opcionales que se instalan bajo demanda; el modo
  single-db / multi-db se decide en `config/tenancy.php`; Sentry se activa con
  `SENTRY_LARAVEL_DSN` y Pulse con `PULSE_ENABLED`. Las variables
  correspondientes salen de `.env.example`.
- `tests/Feature/ExampleTest.php` y `tests/Unit/ExampleTest.php` (la única
  aserción útil, que `/` responde 200, vive ahora en
  `tests/Feature/PublicPagesTest.php`).
- `expect()->extend('toBeOne')`, `function something()` y el `withoutVite()`
  duplicado de `tests/Pest.php` (ya está en `tests/TestCase.php`).
- `InlineConstructorDefaultToPropertyRector` de `withRules()` en `rector.php`:
  ya venía en `SetList::CODE_QUALITY` y Rector avisaba del duplicado en cada
  ejecución.
- `resources/views/vendor/one-time-passwords/*` y
  `resources/views/vendor/pulse/dashboard.blade.php`: eran byte a byte
  idénticas a las del paquete, así que sólo aportaban deriva silenciosa cuando
  el paquete cambiara. Republicarlas es un `vendor:publish` cuando de verdad
  haya que personalizarlas.

[Unreleased]: https://github.com/koreui/kore-laravel/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/koreui/kore-laravel/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/koreui/kore-laravel/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/koreui/kore-laravel/compare/v1.4.1...v2.0.0
[1.4.1]: https://github.com/koreui/kore-laravel/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/koreui/kore-laravel/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/koreui/kore-laravel/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/koreui/kore-laravel/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/koreui/kore-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/koreui/kore-laravel/releases/tag/v1.0.0
