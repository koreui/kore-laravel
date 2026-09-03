# API REST — el contrato de `App\Core\Http\Api`

**TL;DR**: toda la API vive bajo `api/v1`, responde `{ data, meta? }` en éxito y
`{ error: { code, message, details? } }` en fallo, y esas dos formas no las
escribe ningún endpoint: se heredan de `App\Core\Http\Api` (R54). Un endpoint
nuevo son cinco archivos y ninguna decisión de formato.

Se enciende con `API_ENABLED=true` (`kore-app.api.enabled`) y se parametriza en
[`config/kore-api.php`](../../config/kore-api.php).

## Lo que este contrato **no** hace todavía

- **No hay reto de 2FA por API.** Una cuenta con verificación en dos pasos
  confirmada **no puede** pedir un token: `POST /api/v1/auth/login` responde 403
  `two_factor_required` y su dueño entra por el navegador. Ver
  [Autenticación por token](#autenticación-por-token).
- **Auth no guarda dispositivos.** El login acepta `device_id`, `platform` y
  `app_version` y los publica dentro de `ApiTokenIssued`; quien los guarda es el
  módulo opcional **Devices** (`DEVICES_ENABLED`), que escucha ese evento (R5).
  Ver [Dispositivos](#dispositivos).
- **No hay refresh token separado.** `POST /api/v1/auth/refresh` rota el token de
  acceso presentando el token de acceso: no hay dos credenciales con dos vidas.
- **No hay versión 2.** Cuando la haya, `api/v2` convive con `api/v1`: la
  versión es un segmento de la URL, no una cabecera.

## El contrato

### Envelope de éxito

```json
{ "data": { "id": 1, "name": "Ada" } }
```

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": { "next_cursor": "eyJpZCI6Miw…", "prev_cursor": null, "per_page": 25 }
}
```

Lo pone `ApiController::respond()`, y para un `JsonResource` lo pone además el
`$wrap = 'data'` de `BaseApiResource`. `meta` sólo aparece si el endpoint lo
pasa: un recurso único no arrastra un `meta` vacío.

Un `DELETE` o un comando que no devuelve nada responde `204` sin cuerpo con
`respondNoContent()`.

### Envelope de error

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Los datos enviados no son válidos.",
    "details": { "email": ["El campo email es obligatorio."] }
  }
}
```

Lo produce `App\Core\Http\Api\Exceptions\ApiExceptionRenderer`, registrado en
`bootstrap/app.php`, para **cualquier** `Throwable` de una petición `api/*`. Los
códigos son los de `App\Core\Enums\ApiErrorCode`:

| `code`               | Status | Lo dispara |
|----------------------|--------|------------|
| `validation_failed`  | 422 | `ValidationException` (un `BaseApiRequest` o un `$request->validate()`) |
| `unauthenticated`    | 401 | `AuthenticationException` — sin token, o caducado |
| `forbidden`          | 403 | `AuthorizationException` — la Policy dijo que no |
| `not_found`          | 404 | `ModelNotFoundException`, `NotFoundHttpException`, ruta inexistente |
| `method_not_allowed` | 405 | verbo equivocado. Lleva la cabecera `Allow` |
| `conflict`           | 409 | `App\Exceptions\ConflictException` (excepción de dominio) |
| `two_factor_required`| 403 | `App\Exceptions\TwoFactorRequiredException` — la cuenta tiene 2FA y este flujo sólo sabe el primer paso |
| `throttled`          | 429 | rate limit. Lleva `Retry-After` y las `X-RateLimit-*` |
| `bad_request`        | 400 | petición mal formada |
| `upgrade_required`   | 426 | `App\Exceptions\UpgradeRequiredException` — el middleware `devices.version` cortó a un cliente por debajo del mínimo |
| `http_error`         | 4xx | cualquier otro status sin código propio (419, 402, 423…) |
| `server_error`       | 5xx | todo lo demás |

Tres cosas que son decisiones, no detalles:

1. **`details` es exclusiva del 422** y significa siempre «errores por campo».
   Ningún otro código la lleva, así que un cliente puede pintar el formulario
   sin comprobar nada más.
2. **El mensaje lo pone el contrato, no la excepción.** El de un
   `ModelNotFoundException` lleva dentro el FQCN del modelo y el id buscado; el
   de una `AuthorizationException` viene en inglés desde el Gate. Los mensajes
   canónicos están en `ApiErrorCode::message()`, en español y traducidos en
   `lang/en.json` (R33). Las excepciones son `ConflictException` y `UpgradeRequiredException`, cuyo mensaje
   es texto de dominio escrito a propósito.
3. **El 500 no filtra nada.** Con `APP_DEBUG=true` —y sólo entonces— añade un
   bloque `error.debug` con la clase, el mensaje, el archivo y la línea. Nunca
   en producción, donde el detalle vive en Sentry.

El 409 y el `two_factor_required` son los dos únicos que un endpoint dispara a
mano, y los dos lo hacen desde el dominio:

```php
// dentro de una Action, no del controller
throw new ConflictException(__('Este dispositivo ya está registrado en otra cuenta.'));
```

`App\Exceptions\ConflictException` no extiende `HttpException` a propósito: una
Action tiene que seguir siendo ejecutable desde un job o un comando, donde nadie
va a rendir un status (R20). Quien decide que eso es un 409 es el renderer.
`App\Exceptions\TwoFactorRequiredException` es la misma pieza con otro código; a
diferencia del 409, su mensaje lo pone el contrato y no el autor, porque la causa
es siempre la misma.

**Por qué `two_factor_required` y no un `forbidden` a secas.** Un 403 genérico le
dice al cliente «no puedes» y le deja dos reacciones malas: reintentar, o pedirle
al usuario un permiso que nadie le va a dar. Con un código propio, la reacción
correcta es evidente y programable: manda a esa persona a la pantalla de login
del navegador. Un `code` canónico existe precisamente para eso.

Fuera de `api/*` el renderer devuelve `null` y una pantalla web sigue rindiendo
su error en HTML como siempre.

### Paginación

Cursor, no offset: una API la consume un cliente que scrollea, y con `page=N`
insertar una fila mientras el usuario baja duplica o se salta registros.
`ApiController` ya trae `HandlesCursorPagination`:

```php
$devices = $this->paginateWithCursor(Device::query()->latest(), $request);

return $this->respond(DeviceResource::collection($devices), meta: $this->cursorMeta($devices));
```

`?per_page=` lo decide el cliente **acotado** entre 1 y `kore-api.pagination.max`
(100). Sin él, el default es `kore-api.pagination.default` (25). El tope no es
un consejo: sin él, `?per_page=100000` es una denegación de servicio de un solo
carácter.

`meta` siempre trae `next_cursor` (null en la última página), `prev_cursor` y
`per_page`.

### Enums

`EnumResource` publica `{ value, label }`: el `value` es lo que el cliente manda
de vuelta y el `label` lo que enseña. El label sale del método `label()` del
enum si lo tiene y del nombre del case si no.

```php
'role' => EnumResource::make($user->systemRole()),
'roles' => EnumResource::collection(SystemRole::assignable()),
```

## Autenticación por token

**TL;DR**: `POST /api/v1/auth/login` con email, contraseña y `device_name`
devuelve un token Bearer de Sanctum cuyas **abilities son los permisos efectivos
del usuario**. Caduca a los 30 días (`kore-api.tokens.expires_minutes`), se rota
con `/auth/refresh` sin volver a pedir la contraseña, y se retira solo cuando
alguien le cambia los permisos a su dueño.

### Las cinco rutas

| Método | Ruta | Nombre | Middleware | Respuesta |
|--------|------|--------|-----------|-----------|
| `POST` | `/api/v1/auth/login` | `api.v1.auth.login` | `api`, `throttle:api-auth` | 201 `{ data: { token, token_type, expires_at, user } }` |
| `POST` | `/api/v1/auth/refresh` | `api.v1.auth.refresh` | `api`, `auth:sanctum`, `throttle:api-auth` | 201, mismo cuerpo que el login |
| `POST` | `/api/v1/auth/logout` | `api.v1.auth.logout` | `api`, `auth:sanctum` | 204 sin cuerpo |
| `POST` | `/api/v1/auth/logout-all` | `api.v1.auth.logout-all` | `api`, `auth:sanctum` | 204 sin cuerpo |
| `GET` | `/api/v1/auth/me` | `api.v1.auth.me` | `api`, `auth:sanctum` | 200 `{ data: UserMeResource }` |

`GET /api/v1/auth/me` y `GET /api/v1/user` (`api.v1.user.me`) son **el mismo
endpoint con dos nombres**: el segundo existe desde la v2.2.0 y no se rompe; el
primero es el que espera encontrar quien acaba de llamar a `auth/login` y
`auth/logout`.

El login **no** lleva middleware `guest`. Sería un tiro en el pie:
`RedirectIfAuthenticated` responde con un 302 hacia una pantalla web, y volver a
hacer login teniendo un token todavía válido es legítimo —es lo que hace una app
recién reinstalada—.

### El cuerpo del login

```jsonc
{
  "email": "ada@example.com",     // obligatorio
  "password": "…",                // obligatorio
  "device_name": "iPhone de Ada", // OBLIGATORIO: es el nombre del token
  "device_id": "ABC-123",         // opcional
  "platform": "ios",              // opcional: ios | android | web | cli
  "app_version": "1.4.2"          // opcional
}
```

`device_name` no tiene default a propósito. Es lo único que le queda al usuario
para decidir cuál revocar desde una pantalla de «mis sesiones», y cinco filas
llamadas `api` no son una lista de dispositivos: son una lista de nadas.

Los otros tres no se guardan en ninguna tabla. Viajan dentro del evento
`App\Modules\Auth\Events\ApiTokenIssued` para que el módulo que lleve el registro
de dispositivos los use sin que Auth sepa que existe (R5).

### Abilities = permisos

```php
$abilities = $user->getAllPermissions()->pluck('name')->all();  // roles + directos
```

Un token lleva **exactamente** los permisos que su dueño tenía al emitirlo, y si
no tenía ninguno lleva `[]` — nunca `['*']`. El fallback al comodín es tentador y
es el error: le da la llave maestra justo a la cuenta que no tiene ninguna
puerta. Un token con `[]` no abre ningún endpoint que exija una ability, que es
literalmente lo que significa «este usuario no puede nada».

Se comprueban con el middleware `abilities:` de Sanctum, aliasado en
`bootstrap/app.php` (el paquete trae las clases pero no las aliasea):

| Alias | Clase | Semántica |
|-------|-------|-----------|
| `abilities` | `CheckAbilities` | **todas** las que se listen (AND) |
| `ability` | `CheckForAnyAbility` | **al menos una** (OR) |

`abilities:users.edit` en una ruta de API se lee igual que el
`permission:users.edit` de la ruta web equivalente. **No sustituye a la Policy**:
ver [Users API v1](#users-api-v1).

### Caducidad

```php
'tokens' => ['expires_minutes' => env('API_TOKEN_EXPIRES_MINUTES', 43200)],  // 30 días
```

La caducidad va en la fila del token (`expires_at`), no en `sanctum.expiration`,
que sigue en `null` **a propósito**: esa clave es global y **retroactiva**. Al
ponerla, todos los tokens ya emitidos pasan a caducar contados desde su
`created_at`, y la integración que llevaba dos años funcionando se cae el día del
deploy. Con `expires_minutes` a `null` el token no caduca, que sigue siendo una
decisión legítima cuando el ciclo de vida lo lleva la revocación.

`expires_at` se publica en ISO 8601 y no como «segundos restantes»: un
`expires_in: 2592000` obliga al cliente a saber cuándo empezó a contar, y el
único reloj que tiene a mano es el suyo.

### Credenciales inválidas

422 `validation_failed` con `details.email`, y **el mismo mensaje** tanto si el
email no existe como si la contraseña es otra: decir «ese correo no está
registrado» convierte el login en un censo de cuentas (R28). El texto sale de
`auth.failed`, la misma frase que ve quien falla el login en el navegador.

Hay una segunda puerta que cerrar, la del reloj: sin ella, la respuesta a un
email inexistente vuelve en microsegundos y el tiempo delata lo que el mensaje
calla. Por eso el controller gasta un `Hash::make()` de descarte cuando no
encuentra al usuario.

El intento fallido cuenta igual en el limiter: `throttle:api-auth` corre por
delante del controller y suma en cada petición, salga como salga. Son 5 por
minuto **y por IP** (R28) — quien fuerza credenciales todavía no tiene usuario,
así que limitar por usuario no limitaría nada.

### 2FA: la decisión

**Una cuenta con 2FA confirmado no entra por la API.** `POST /auth/login`
responde 403 `two_factor_required`.

No es una limitación temporal disfrazada de política: mientras el reto por API no
exista, aceptar email + contraseña sería publicar una puerta que se salta el
segundo factor que esa persona activó a propósito, y la API dejaría de ser una
vista de la aplicación para pasar a ser su punto más débil. Ni Notarium ni
asper-server miran el 2FA en su login de API, y los dos tienen el agujero
abierto.

Dos matices:

- Se mira también el toggle. Con `AUTH_2FA_ENABLED=false` no hay segundo factor
  que respetar y un `two_factor_secret` viejo en la tabla no puede dejar a nadie
  fuera.
- **Confirmado**, no «empezado». Quien escaneó el QR pero no metió el código
  sigue entrando: su cuenta todavía no está protegida por nada.

Cuando llegue el reto por API será un endpoint más (`/auth/two-factor-challenge`),
y este 403 pasará a ser su invitación en vez de un final.

### Revocación al cambiar permisos

Las abilities de un token se congelan al emitirlo. Sin nada más, degradar a
alguien en la pantalla de usuarios le quita el botón del navegador y **no le
quita nada de la API**: su móvil sigue presentando un token con `users.delete`
dentro hasta que caduque, treinta días después. Es el agujero que R26 cierra en
la UI, visto desde el otro lado del cable.

Lo cierra `App\Modules\Auth\Listeners\RevokeApiTokensOnPermissionChange`, que
escucha los cuatro eventos de spatie/laravel-permission
(`RoleAttachedEvent`, `RoleDetachedEvent`, `PermissionAttachedEvent`,
`PermissionDetachedEvent`) y retira **todos** los tokens del usuario afectado,
disparando `ApiTokenRevoked(reason: 'permissions_changed')`.

> ⚠️ Requiere `'events_enabled' => true` en `config/permission.php`. El default
> del paquete es `false`, y con esa clave apagada el listener no se ejecuta nunca
> sin que nadie se entere. `ApiTokenRevocationTest` comprueba el valor.

Es un martillo, y a propósito: al usuario se le cierran todas las sesiones de
API, incluida la que tuviera abierta. La alternativa —recalcular las abilities de
cada token vivo— deja al cliente con un token cuyo contenido cambió sin avisar,
que es peor de depurar que un re-login.

**Lo que no cubre**: cambiar los permisos de un *rol* (no de un usuario) se
ignora. `ModulesSeeder` y `kore:auth:permissions` sincronizan los permisos de
todos los roles en cada despliegue, y reaccionar a eso echaría a toda la
plantilla de sus móviles cada vez que alguien añade un módulo. Un proyecto que
necesite cubrirlo lo hace desde un job, no desde un listener síncrono.

### Los eventos

| Evento | Cuándo | `reason` |
|--------|--------|----------|
| `ApiTokenIssued` | login, refresco | — |
| `ApiTokenRevoked` | logout | `logout` |
| `ApiTokenRevoked` | logout de todos (`tokenId` es `null`) | `logout_all` |
| `ApiTokenRevoked` | refresco (el token viejo) | `refresh` |
| `ApiTokenRevoked` | cambio de permisos (`tokenId` es `null`) | `permissions_changed` |

`reason` es texto libre y viaja en el evento porque quien escucha necesita
distinguir un cierre de sesión voluntario de una revocación forzada, y esa
distinción se pierde si sólo se publica el hecho.

### De punta a punta con `curl`

```bash
# 1 · Entrar
curl -sX POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@kore.test","password":"password","device_name":"curl","platform":"cli"}'
# → 201 { "data": { "token": "3|xxxx…", "token_type": "Bearer",
#          "expires_at": "2026-10-03T12:00:00+00:00", "user": { … } } }

TOKEN='3|xxxx…'

# 2 · Usarlo
curl -s http://localhost:8000/api/v1/auth/me -H "Authorization: Bearer $TOKEN"

# 3 · Rotarlo (el viejo deja de valer en el acto)
curl -sX POST http://localhost:8000/api/v1/auth/refresh -H "Authorization: Bearer $TOKEN"

# 4 · Salir de este dispositivo · 204 sin cuerpo
curl -isX POST http://localhost:8000/api/v1/auth/logout -H "Authorization: Bearer $TOKEN"

# 5 · Salir de todos
curl -isX POST http://localhost:8000/api/v1/auth/logout-all -H "Authorization: Bearer $TOKEN"
```

### Las piezas

| Archivo | Qué hace |
|---------|----------|
| `Auth/Http/Controllers/Api/V1/AuthTokenController.php` | autoriza, arma el DTO, llama a la Action |
| `Auth/Http/Requests/Api/V1/LoginRequest.php` | valida la **forma** del cuerpo y produce `ApiLoginData` |
| `Auth/Actions/AuthApiTokenIssueAction.php` | abilities + caducidad + `createToken` + evento |
| `Auth/Actions/AuthApiTokenRevokeAction.php` | retira uno o todos + evento |
| `Auth/Data/{ApiLoginData,ApiDeviceData,ApiTokenData}.php` | los tres DTOs (R8) |
| `Auth/Http/Resources/Api/V1/ApiTokenResource.php` | `{ token, token_type, expires_at, user }` |
| `Auth/Listeners/RevokeApiTokensOnPermissionChange.php` | R26 llevado al token |

Comprobar la contraseña vive en el controller y no en una Action a propósito:
decidir **quién** pregunta es autenticación, no negocio, y es lo mismo que hace
el `LoginRequest` de Fortify. El caso de uso —emitir un token con estas abilities
y esta caducidad— sí es la Action (R4).

## Users API v1

`api/v1/users` es el **endpoint de referencia** del boilerplate: el que hay que
copiar cuando un módulo nuevo necesita publicar su recurso. Enseña las cinco
piezas juntas y no reimplementa ninguna — las Actions, los DTOs y las reglas
anti-escalada son literalmente las mismas que usa la pantalla Livewire.

| Método | Ruta | Nombre | Ability | Policy | Respuesta |
|--------|------|--------|---------|--------|-----------|
| `GET` | `/api/v1/users` | `api.v1.users.index` | `users.view` | `viewAny` | 200 `{ data: [...], meta }` |
| `GET` | `/api/v1/users/{user}` | `api.v1.users.show` | `users.view` | `view` | 200 `{ data }` |
| `POST` | `/api/v1/users` | `api.v1.users.store` | `users.create` | `create` | 201 `{ data }` |
| `PUT`/`PATCH` | `/api/v1/users/{user}` | `api.v1.users.update` | `users.edit` | `update` | 200 `{ data }` |
| `DELETE` | `/api/v1/users/{user}` | `api.v1.users.destroy` | `users.delete` | `delete` | 204 sin cuerpo |

Las rutas viven en `app/Modules/Users/Routes/api.php` y su provider las carga
sólo con `API_ENABLED=true`, igual que hace Auth.

### La doble barrera (R23 · R25)

Cada ruta exige la ability del token **y** el método vuelve a preguntarle a la
Policy. No es redundante: son dos preguntas distintas.

- La **ability** dice qué se le concedió a *este token* cuando se emitió. Es lo
  que hace que un token robado de una app de sólo lectura no pueda borrar nada
  aunque su dueño sea administrador.
- La **Policy** dice qué puede *este usuario* ahora mismo sobre *este registro*.
  «Sólo un superadmin edita a otro superadmin» no es algo que una ability pueda
  expresar: depende del registro, no del token.

Quitar cualquiera de las dos deja un agujero distinto. Es la misma forma que
tiene la UI, donde la ruta lleva `permission:users.edit` y el componente Livewire
vuelve a autorizar porque `/livewire/update` no pasa por el middleware de la
ruta.

### El superadmin no sale

El listado excluye a los superadmins, exactamente igual que `TableUsers`: es un
rol que sólo se asigna por consola, y publicarlo sería regalarle a cualquiera con
`users.view` la lista de las cuentas que más interesa atacar. Que la pantalla los
oculte y la API los enseñara sería tener dos respuestas a la misma pregunta.

Editarlos y borrarlos lo bloquea `UserPolicy` (403), y borrarse a uno mismo lo
bloquea el controller con un `abort_if` — porque el `Gate::before` del superadmin
devuelve `true` antes de que la Policy llegue a opinar.

### Filtros y paginación

```bash
GET /api/v1/users?search=ada&role=Administrador&per_page=25&cursor=eyJpZCI6Miw…
```

`search` casa contra nombre **y** email; `role` es el nombre exacto del rol. El
orden es por id descendente y no por `created_at`: la paginación por cursor
necesita una columna única para no saltarse ni repetir filas, y dos usuarios
sembrados en la misma transacción comparten `created_at`.

### Anti-escalada por API (R26)

El rol y los permisos que llegan por `POST`/`PUT` pasan por las mismas `Rules`
que el formulario —`GrantableRole` y `GrantablePermission`—, así que nadie
concede lo que no tiene. Un intento sale como un 422 con el motivo en `details`:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Los datos enviados no son válidos.",
    "details": {
      "permissions.0": ["No puedes conceder el permiso «users.delete» porque tú no lo tienes."]
    }
  }
}
```

> **Cicatriz (v2.2.0).** Esa regla estuvo **desactivada en la API** el tiempo que
> tardó en escribirse su primer test.
> `AuthorizationCatalog::permissionsForRole()` filtraba por
> `config('auth.defaults.guard')`, y `AuthManager::shouldUse()` —que es lo que
> llama `auth:sanctum`— **escribe** esa clave con el valor `sanctum`. Los roles
> se siembran con `guard_name = 'web'`, así que en toda petición de la API el
> método devolvía `[]`, `GrantableRole` no encontraba ningún permiso «que el
> actor no tenga» y dejaba pasar cualquier rol: un `users.create` podía crear
> administradores. Hoy el guard sale de `Guard::getDefaultName(User::class)`,
> que es la misma resolución que usa spatie por dentro, y
> `AuthorizationSeederTest` lo comprueba bajo los dos guards.

### El cuerpo

```jsonc
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "…",                 // obligatorio al crear, opcional al editar
  "password_confirmation": "…",
  "role": "Usuario",
  "permissions": ["users.view"]     // permisos DIRECTOS, además de los del rol
}
```

Omitir `password` al editar significa «no la cambies». `PATCH` comparte reglas
con `PUT`: un PATCH que validara sólo lo que llega evaluaría `GrantableRole`
contra un usuario del que no sabe el resto.

## Cómo añadir un endpoint

Supongamos `GET /api/v1/devices` y `POST /api/v1/devices` en un módulo
`Devices`. Cinco archivos, ninguna decisión de formato.

### 1 · El resource — `Http/Resources/Api/V1/DeviceResource.php`

```php
namespace App\Modules\Devices\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Modules\Devices\Models\Device;
use Illuminate\Http\Request;

/** @mixin Device */
final class DeviceResource extends BaseApiResource
{
    /**
     * @return array{id: string, name: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'name' => $this->name];
    }
}
```

Es una **lista blanca**, no un `toArray()` del modelo: lo que no esté escrito
aquí no sale. El `@mixin` es lo que hace que el IDE y Larastan vean los
atributos del modelo detrás de `$this`.

### 2 · El request — `Http/Requests/Api/V1/DeviceStoreRequest.php`

```php
namespace App\Modules\Devices\Http\Requests\Api\V1;

use App\Core\Http\Api\Requests\BaseApiRequest;

final class DeviceStoreRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }
}
```

Extiende `BaseApiRequest` y **no** `FormRequest`: es lo que convierte un fallo
de validación en el 422 con `details` en vez de un redirect 302. No sobreescribas
`authorize()`: la autorización va en el controller, contra la Policy (R25).

### 3 · El controller — `Http/Controllers/Api/V1/DeviceController.php`

```php
namespace App\Modules\Devices\Http\Controllers\Api\V1;

use App\Core\Http\Api\Controllers\ApiController;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;

#[Group('Devices')]
final class DeviceController extends ApiController
{
    /**
     * Dispositivos del usuario.
     */
    #[ApiResponse(200, type: DeviceResource::class)]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $devices = $this->paginateWithCursor(Device::query()->latest(), $request);

        return $this->respond(DeviceResource::collection($devices), meta: $this->cursorMeta($devices));
    }

    /**
     * Registra un dispositivo.
     */
    #[ApiResponse(201, type: DeviceResource::class)]
    public function store(DeviceStoreRequest $request, DeviceRegisterAction $action): JsonResponse
    {
        $this->authorize('create', Device::class);

        $device = $action->handle(DeviceData::from($request->validated()), $request->user());

        return $this->respond(DeviceResource::make($device), status: 201);
    }
}
```

Reglas que siguen valiendo aquí: sin lógica de negocio (R4 — el controller
autoriza, arma el DTO y llama a la Action), sin `abort()` dentro de `App\Core`
(R20 — desde un módulo sí puedes, pero prefiere la excepción), y un
`declare(strict_types=1)` arriba (R13).

### 4 · La ruta — `Routes/api.php` del módulo

```php
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/'.config('kore-api.version', 'v1'))
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');

        Route::post('/devices', [DeviceController::class, 'store'])
            ->middleware('throttle:api-uploads')
            ->name('devices.store');
    });
```

El provider del módulo la carga sólo con el toggle encendido:

```php
if ((bool) config('kore-app.api.enabled')) {
    $this->loadRoutesFrom("{$base}/Routes/api.php");
}
```

### 5 · El test — `Tests/Feature/DeviceApiTest.php` del módulo

Cobertura mínima, la misma que pide R35 para cualquier ruta:

- `401` sin token,
- `403` sin el permiso,
- `200`/`201` con el shape esperado (`assertExactJsonStructure(['data' => [...]])`),
- `404` cuando el recurso no existe,
- `422` con `details` cuando la validación falla.

Los códigos se afirman por su nombre canónico, no por el status a secas:
`->assertJsonPath('error.code', 'forbidden')`.

## Middleware

Tres alias, registrados en `bootstrap/app.php`:

| Alias | Clase | Dónde |
|-------|-------|-------|
| `api.json` | `ForceJsonResponse` | en el grupo `api`, por delante del throttle |
| `api.audit` | `ApiAuditLogger` | en el grupo `api`, por delante del throttle |
| `api.cache` | `ApiCacheableResponse` | endpoint a endpoint (`api.cache:3600`) |

- **`api.json`** fuerza `Accept: application/json`. Los clientes reales no
  siempre lo mandan: al subir un `FormData` desde React Native se omiten las
  cabeceras para que el runtime ponga el `boundary`, y `curl` no manda ninguna.
  La otra mitad del cinturón es el `shouldRenderJsonWhen()` de
  `bootstrap/app.php`, que mira la URL en vez de la cabecera y por eso cubre lo
  que revienta antes de llegar aquí.
- **`api.audit`** escribe una línea estructurada por petición —método, ruta,
  nombre de ruta, usuario, status, milisegundos, IP— en el canal `api` de
  `config/logging.php`. **Nunca el cuerpo**: un log de peticiones que guarda el
  body acaba guardando contraseñas en un archivo que se rota a otro disco. El
  canal es un stack que por defecto va a `single`; `LOG_API_STACK=daily` o
  `=stderr` lo saca de ahí sin tocar código.
- **`api.cache`** pone ETag (`xxh128` del cuerpo) y `Cache-Control: private,
  max-age=N`, y responde `304` sin cuerpo si el `If-None-Match` coincide. Sólo
  actúa sobre `GET` con `200` y cuerpo JSON. No está en el grupo a propósito:
  cachear lo que cambia en cada petición es pagar un hash para nada.

Los dos del grupo van **prepend** y no append. Detrás del `throttle:api`, el 429
—la petición que más interesa auditar— se rendiría sin dejar línea de log.

## Rate limiting (R28)

Tres limiters nombrados, registrados en
`AppServiceProvider::configureApiRateLimiters()` con las cifras de
`config/kore-api.php`:

| Limiter | Por minuto | Agrupa por | Para qué |
|---------|-----------|------------|----------|
| `api` | 60 | usuario, o IP si es anónimo | el del grupo `api`, todo `api/v1/*` |
| `api-auth` | 5 | **IP** | login, registro, magic link, refresco de token |
| `api-uploads` | 30 | usuario | subidas de archivo |

`api-auth` va por IP a propósito: quien fuerza credenciales todavía no tiene
usuario, así que limitar por usuario no limitaría nada.

Se aplican con `->middleware('throttle:api-auth')` sobre la ruta o el grupo. El
`api` ya lo aplica `throttleApi()` a todo el grupo.

Se registran **siempre**, también con `API_ENABLED=false`: el toggle decide si se
cargan las rutas, no si existe el limiter. Y sin `RateLimiter::for('api')`,
`throttle:api` degradaría a `maxAttempts = (int) 'api' = 0` y bloquearía todas
las peticiones — Laravel no trae ninguno de fábrica.

## Dispositivos

El módulo opcional **Devices** (`DEVICES_ENABLED`, apagado por defecto) es hoy el
único consumidor real de este contrato aparte de `GET /api/v1/user`, y el ejemplo
del que copiar cuando un endpoint tiene que hacer algo más que devolver una fila:

| Ruta | Qué hace |
|------|----------|
| `GET /api/v1/devices` | los del usuario del token, por cursor, con `current` |
| `DELETE /api/v1/devices/{device:uuid}` | 204; revoca el dispositivo y borra su token de Sanctum |
| `PUT /api/v1/devices/current/push-token` | 204; guarda el token de notificaciones del dispositivo en uso |

Tres cosas que se repiten tal cual en cualquier recurso «de mi cuenta»:

- **Ningún endpoint acepta un `user_id`**: el dueño sale del token y la consulta
  se acota por él antes de buscar nada, así que el recurso de otro es un `404` y
  no un `403` que confirmaría que existe.
- **La identidad pública es un `uuid`** (`App\Core\Concerns\HasPublicUuid` con
  `ROUTE_BY_UUID`), no el id entero, que diría cuántas filas hay.
- **El resource es una lista blanca de verdad**: el `push_token` no aparece ni
  aquí ni en el modelo (`#[Hidden]`).

El módulo añade además un middleware **opt-in**, alias `devices.version`, que
responde `426` con `error.code: upgrade_required` a los clientes por debajo de
`config('devices.min_app_version')`. Lo hace lanzando `UpgradeRequiredException`, que
`ApiExceptionRenderer` rinde con `ApiErrorCode::UpgradeRequired` (R54). No va en
el grupo `api` a propósito.

Detalle completo —modelo, eventos que escucha, comando de limpieza y config— en
[`../modules/devices.md`](../modules/devices.md).

## Documentación OpenAPI (Scramble)

| | |
|---|---|
| UI | `GET /api/docs` (Stoplight Elements) |
| Spec | `GET /api/docs.json` |
| Toggle | `API_DOCS` → `config('kore-api.docs.enabled')`, **`false` por defecto** |
| Acceso | libre en `local`; sólo `superadmin` fuera de local (gate `viewApiDocs`) |
| Export | `php artisan scramble:export` → `storage/app/openapi.json` |

**Por qué en `/api/docs` y no en `/docs/api`.** El módulo Docs registra
`GET /docs/{path}` con `where('path', '[A-Za-z0-9_\-/]+')`, que casa `docs/api`,
y sus rutas se registran antes que las de Scramble (que espera al `booted()`).
Con los dos toggles encendidos, el visor de markdown se quedaba la URL de la
documentación de la API. Moverla la deja sin ambigüedad y de paso la agrupa con
lo que documenta.

**Cómo se apaga de verdad.** `ApiDocsServiceProvider::register()` escribe
`Scramble::$defaultRoutesIgnored`, y lo hace en `register()` porque Scramble
expone sus rutas desde el `boot()` de su propio provider, que arranca antes que
los de la aplicación. Con el toggle apagado, `/api/docs` es un 404 como
cualquier otra ruta inexistente.

**Qué se documenta.** Sólo `api/v*`: el filtro está en
`Scramble::configure()->routes(...)`, así que `api/docs` no se documenta a sí
misma. `config/scramble.php` conserva `api_path` con el comodín `api/v*` porque
Scramble lo sigue usando para dos cosas más — el `server` del documento y si se
recorta el prefijo de cada `path`—; con comodín, los paths salen enteros
(`/api/v1/user`), que es lo que un cliente copia y pega.

**Cómo mejorar el schema de una respuesta.** `respond()` devuelve un
`JsonResponse`, que para Scramble es opaco. El atributo lo arregla:

```php
#[ApiResponse(200, type: DeviceResource::class)]
```

Con él, la spec publica el envelope completo (`{ data: DeviceResource }`) y el
schema del resource en `components/schemas`. Sin él, el 200 sale como un
`object` sin propiedades. Usa también `#[Group('Devices')]` en la clase para
agrupar los endpoints en la UI, y el docblock del método para el `summary` y la
`description`.

**El export es un artefacto, no una fuente.** Va a `storage/app/openapi.json`,
que está en `.gitignore`: una spec commiteada garantiza que algún día mienta.
Quien la necesita —un cliente que genera su SDK, un contrato en CI— la exporta
en el momento con `php artisan scramble:export` (o `--path=` a donde quiera).

**Dev tools.** Scramble registra un asset de sus herramientas de desarrollo
siguiendo `APP_DEBUG`; en el boilerplate se apagan por defecto
(`config/scramble.php` → `dev_tools.enabled`) para no dejar una ruta suelta en
cualquier entorno con debug. Se encienden con `SCRAMBLE_DEV_TOOLS=true`.

## Comandos

```bash
php artisan route:list --path=api              # qué hay publicado
API_DOCS=true php artisan route:list --path=api # ...con la doc encendida
API_DOCS=true php artisan scramble:export      # spec a storage/app/openapi.json
./vendor/bin/pest tests/Feature/Api            # el contrato entero
./vendor/bin/pest tests/Arch                   # R54: quién hereda de quién

# Los endpoints
./vendor/bin/pest --filter=ApiAuth             # login, refresco, logout
./vendor/bin/pest --filter=ApiTokenRevocation  # R26 llevado al token
./vendor/bin/pest --filter=ApiUsers            # el CRUD de referencia
```

> `scramble:export` **abre la base de datos**: infiere los tipos de los resources
> mirando el esquema de los modelos. En un checkout recién clonado hay que correr
> `php artisan migrate` antes, o el comando muere con un
> `Database file … does not exist`.

## Reglas relacionadas

- **[R54](../architecture/rules.md)** — toda respuesta de la API pasa por el
  contrato de Core. Es la regla que este documento explica.
- **[R28](../architecture/rules.md)** — rate limit en todo endpoint que envía
  correo, y de ahí los tres limiters.
- **[R4](../architecture/rules.md)** — sin lógica de negocio en el controller:
  autorizar, armar el DTO, llamar a la Action.
- **[R8](../architecture/rules.md)** — DTOs entre capas. Un resource es la forma
  con la que un dato **sale**; un DTO, la forma que tiene por dentro.
- **[R25](../architecture/rules.md)** — la Policy es el único punto de decisión,
  y por eso `BaseApiRequest::authorize()` devuelve `true`.
- **[R33](../architecture/rules.md) · [R34](../architecture/rules.md)** — los
  mensajes de error son español con `__()` y se traducen en `lang/en.json`.
