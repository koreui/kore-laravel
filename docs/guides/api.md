# API REST — el contrato de `App\Core\Http\Api`

**TL;DR**: toda la API vive bajo `api/v1`, responde `{ data, meta? }` en éxito y
`{ error: { code, message, details? } }` en fallo, y esas dos formas no las
escribe ningún endpoint: se heredan de `App\Core\Http\Api` (R54). Un endpoint
nuevo son cinco archivos y ninguna decisión de formato.

Se enciende con `API_ENABLED=true` (`kore-app.api.enabled`) y se parametriza en
[`config/kore-api.php`](../../config/kore-api.php).

## Lo que este contrato **no** hace todavía

- **No hay autenticación por token propia.** `POST /api/v1/auth/login`,
  `logout`, refresco y registro de dispositivo llegan en la fase siguiente. Hoy
  la única forma de autenticarse es un token de Sanctum creado desde el web
  (`$user->createToken(...)`) o la sesión, vía `EnsureFrontendRequestsAreStateful`.
- **No hay endpoints de negocio.** El único que existe (`GET /api/v1/user`) está
  ahí para que el contrato tenga un ejemplo vivo.
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
| `throttled`          | 429 | rate limit. Lleva `Retry-After` y las `X-RateLimit-*` |
| `bad_request`        | 400 | petición mal formada |
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
   `lang/en.json` (R33). La única excepción es `ConflictException`, cuyo mensaje
   es texto de dominio escrito a propósito.
3. **El 500 no filtra nada.** Con `APP_DEBUG=true` —y sólo entonces— añade un
   bloque `error.debug` con la clase, el mensaje, el archivo y la línea. Nunca
   en producción, donde el detalle vive en Sentry.

El 409 es el único que un endpoint dispara a mano, y lo hace desde el dominio:

```php
// dentro de una Action, no del controller
throw new ConflictException(__('Este dispositivo ya está registrado en otra cuenta.'));
```

`App\Exceptions\ConflictException` no extiende `HttpException` a propósito: una
Action tiene que seguir siendo ejecutable desde un job o un comando, donde nadie
va a rendir un status (R20). Quien decide que eso es un 409 es el renderer.

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
```

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
