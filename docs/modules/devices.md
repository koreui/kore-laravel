# Módulo Devices — dispositivos que consumen la API

**TL;DR**: `DEVICES_ENABLED=true` enciende un inventario de los clientes que
hablan con la API —una app móvil, un SPA, un CLI—. El módulo **no** registra a
nadie: escucha los dos eventos de Auth (`ApiTokenIssued`, `ApiTokenRevoked`) y
mantiene la tabla al día. Encima publica tres endpoints (`GET /devices`,
`DELETE /devices/{uuid}`, `PUT /devices/current/push-token`), un middleware
opt-in de versión mínima de cliente y un `devices:cleanup` diario.

Con el toggle apagado no existe nada de eso; la tabla `devices` se migra igual.

## Por qué existe

En cuanto una API tiene login por token, aparecen tres preguntas que el token
solo no responde:

1. **«¿Dónde tengo la sesión abierta?»** — la pantalla de dispositivos que toda
   app con login acaba necesitando, y el botón de cerrar sesión en el teléfono
   que se perdió.
2. **«¿A dónde mando la notificación?»** — el push token de FCM/APNs cuelga del
   dispositivo, no del usuario: un usuario tiene tantos como aparatos.
3. **«¿Quién sigue usando la versión de hace dos años?»** — y cómo se le corta
   el paso sin romperle la pantalla desde la que puede actualizar.

Salió de la cantera de Notarium (`MobileDevice`, `DeviceController`,
`EnsureMobileVersion`, `mobile:cleanup-*`), donde las tres estaban resueltas
—bien— pero atadas a «móvil». Aquí el vocabulario es «cliente de API»: la
plataforma es un enum con cuatro casos y el CLI cuenta como dispositivo.

## Toggle

| Variable | Default | Qué enciende |
|----------|---------|--------------|
| `DEVICES_ENABLED` | `false` | Rutas `api/v1/devices/*`, listeners de los eventos de Auth, policy, alias `devices.version`, comando `devices:cleanup` y su tarea del scheduler |

Es una de las catorce claves de `config/kore-app.php` y la lee
`DevicesModuleServiceProvider` (más `routes/console.php`, para el scheduler, y
`AppServiceProvider::configureAbout()`).

**Qué NO apaga el toggle: el esquema.** La migración de `devices` se carga
siempre, antes del `return` temprano. Es la misma decisión que
`AUTH_PASSKEYS=false`, que deja de registrar la feature de Fortify y la pantalla
mientras la tabla `passkeys` se migra igual: si la migración fuera condicional,
dos instalaciones del mismo commit tendrían bases distintas según el `.env` del
día en que se migró, y encender el toggle en producción exigiría una migración a
mano justo cuando ya hay tráfico. Ver [`../architecture/toggles.md`](../architecture/toggles.md).

Las rutas piden **los dos** toggles: `DEVICES_ENABLED` y `API_ENABLED`. Un
derivado puede querer el inventario y su purga sin publicar la API.

## Cómo se llena la tabla (R5)

```
App\Modules\Auth                          App\Modules\Devices
────────────────                          ───────────────────
login  ─▶ ApiTokenIssued  ──────────────▶ RegisterDeviceOnTokenIssued
                                              └─▶ DeviceRegisterAction
logout ─▶ ApiTokenRevoked ──────────────▶ RevokeDeviceOnTokenRevoked
```

`App\Modules\Auth\Events\*` es lo único de Auth que este módulo importa, y Auth
no sabe que Devices existe. Apagar el toggle deja de registrar los dos listeners
y el login por API sigue funcionando exactamente igual.

- **`RegisterDeviceOnTokenIssued`** — si el evento trae `deviceId`, hace
  `updateOrCreate` sobre `[user_id, device_id]` con `name` (el nombre del
  token), `platform`, `app_version`, `access_token_id` y `last_seen_at`, y pone
  `revoked_at` de vuelta a `null`: volver a entrar es lo que resucita un
  dispositivo revocado. **Sin `deviceId` no hace nada** — un token creado desde
  el panel web o por un `php artisan` no identifica ningún aparato.
- **`RevokeDeviceOnTokenRevoked`** — con `tokenId` revoca el dispositivo que
  colgaba de ese token; con `tokenId = null` (logout de todos los dispositivos,
  cambio de contraseña, cuenta comprometida) revoca todos los del usuario. No
  toca los que ya estaban revocados, para no reiniciarles el reloj de retención.

## El modelo

`app/Modules/Devices/Models/Device.php`, tabla `devices`:

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | `bigint` | llave primaria, nunca sale por la API |
| `uuid` | `uuid` único | identidad pública (`HasPublicUuid` + `ROUTE_BY_UUID`) |
| `user_id` | FK `users` | `cascadeOnDelete` |
| `device_id` | `string(191)` | lo elige el cliente; único junto a `user_id` |
| `name` | `string?` | etiqueta que reconoce el usuario («iPhone de Ada») |
| `platform` | `string(20)?` | casteado a `Enums\Platform` |
| `app_version` | `string(32)?` | lo que el cliente declara |
| `push_token` | `text?` | **`#[Hidden]`**: nunca sale por la API |
| `access_token_id` | FK `personal_access_tokens`? | `nullOnDelete` |
| `last_seen_at` | `timestamp?` | indexada; la mueve el login y el push token |
| `revoked_at` | `timestamp?` | indexada; `null` = activo (scope `active()`) |

Tres decisiones que no son detalles:

- **La llave primaria sigue siendo entera y el uuid es la identidad hacia
  afuera.** Un `DELETE /api/v1/devices/7` diría cuántos dispositivos hay en la
  instalación y haría que probar el 8 fuera gratis.
- **`push_token` está tapado dos veces**: `#[Hidden]` en el modelo y ausente de
  `DeviceResource`. Es una credencial de envío — quien la tiene puede mandar
  notificaciones a ese teléfono.
- **`access_token_id` es `nullOnDelete` y no `cascade`.** Cuando el token
  caduca o lo purga `sanctum:prune-expired`, la fila del dispositivo sobrevive
  para poder auditar desde dónde se entró. Lo que se pierde es el vínculo.

## `PushTokenDirectory`: quién puede leer esos tokens

El `push_token` de esta tabla es el único sitio del boilerplate donde se guardan
las credenciales de envío de notificaciones a un teléfono, así que el módulo que
**manda** los push —**Notifications**, detrás de `NOTIFICATIONS_ENABLED`— tiene
que poder preguntarlos sin importar una sola clase de aquí (R5). La frontera es
un contrato de Core:

```php
namespace App\Core\Contracts;

interface PushTokenDirectory
{
    /** @return array<int, string> */
    public function tokensFor(int $userId): array;
}
```

- Lo implementa `Devices\Support\DevicePushTokens`, que devuelve los tokens de
  los dispositivos **activos** —a un revocado no se le manda nada: es un teléfono
  vendido, perdido o cuya sesión alguien cerró a propósito— y **sin repetir**,
  porque reinstalar la app puede devolver el mismo token en dos filas.
- El binding lo pone `DevicesModuleServiceProvider::register()` y **sólo con
  `DEVICES_ENABLED=true`**. Con el módulo apagado no está bindeado, y quien lo
  consume pregunta antes por `bound()` en vez de resolverlo: una instalación sin
  inventario no tiene a dónde mandar un push, y eso no puede tumbar un aviso que
  ya está en la bandeja.
- Devices no sabe que Notifications existe, ni al revés: toda la relación son esa
  interfaz y veinte líneas de implementación. Ver
  [`notifications.md`](notifications.md).

`Enums/Platform` tiene cuatro casos —`ios`, `android`, `web`, `cli`— con su
`label()` (`iOS`, `Android`, `Web`, `CLI`) y un `supportsPush()`. No describe el
aparato (para eso está `name`): separa los tres tipos de cliente que se tratan
distinto. `config('devices.platforms')` es la lista blanca efectiva y puede ser
un subconjunto; lo que no esté en ella se guarda como `platform = null` en vez
de reventar el login.

## Endpoints

Los tres van bajo `api/v1`, detrás de `auth:sanctum`, con el contrato de
[`../guides/api.md`](../guides/api.md) (R54): `{ data, meta? }` en éxito y
`{ error: { code, message, details? } }` en fallo.

**Ninguno acepta un `user_id`.** El dueño sale siempre del token y la consulta se
acota por él **antes** de buscar nada: pedir el uuid de otro es un `404`, no un
`403`. Un 403 confirmaría que ese uuid existe. Es el criterio de las passkeys, y
por eso `DevicePolicy` es la segunda barrera y no la única (el `Gate::before` del
superadmin la haría pasar sin mirar el dueño).

### `GET /api/v1/devices`

Los del usuario autenticado, revocados incluidos, paginados por cursor.

```json
{
  "data": [
    {
      "uuid": "6f1c…",
      "name": "iPhone de Ada",
      "platform": { "value": "ios", "label": "iOS" },
      "app_version": "2.4.0",
      "last_seen_at": "2026-09-03T10:12:00+00:00",
      "revoked_at": null,
      "current": true
    }
  ],
  "meta": { "next_cursor": null, "prev_cursor": null, "per_page": 25 }
}
```

`current` se calcula contra el token de la petición, así que el mismo
dispositivo sale `true` sólo cuando es él quien pregunta: es lo que permite a la
app pintar «este dispositivo» y no ofrecer cerrar la sesión en la que estás sin
avisar.

El orden es por `id` descendente y no por `last_seen_at` a propósito: la
paginación por cursor compara las columnas del `ORDER BY`, y `last_seen_at` es
nullable.

### `DELETE /api/v1/devices/{device:uuid}`

`204` sin cuerpo. Marca `revoked_at` **y borra el token de Sanctum**, las dos
cosas dentro de una transacción: revocar sin borrar el token dejaría el
dispositivo marcado y funcionando hasta que el token caducara, que es el peor de
los dos mundos.

Si el dispositivo es el que hace la petición, el token que se borra es el de esa
misma petición: el 204 se responde y la siguiente llamada del cliente es un
`401`. No hace falta ningún caso especial.

`404` (`error.code: not_found`) si el uuid no existe **o** es de otra cuenta.

La fila no se borra: la revocación es lo que permite responder «¿desde qué
dispositivo se entró?». Quien la borra es `devices:cleanup`.

### `PUT /api/v1/devices/current/push-token`

```json
{ "push_token": "fcm-token-del-dispositivo" }
```

`204` sin cuerpo. El dispositivo se identifica por el **token de Sanctum en
uso**, no por un id que mande el cliente: así una app no puede reescribir el
push token de otro dispositivo de la misma cuenta pasando su uuid.

- `422` con `details.push_token` si falta o pasa de 1024 caracteres.
- `404` si el token en uso no tiene dispositivo registrado (una sesión web, un
  login sin `device_id`) o si su dispositivo está revocado: es un cliente que se
  cree un dispositivo y no lo es, y responderle `204` sería mentirle.

**El servidor no valida el token contra el proveedor: lo guarda.** Un token
inválido se descubre al enviar, y comprobarlo aquí costaría una llamada de red
por petición (R22) en un endpoint que sólo escribe una columna.

## Middleware `devices.version`

`App\Modules\Devices\Http\Middleware\EnsureClientVersion`, alias
`devices.version`. Lee la cabecera **`X-App-Version`** y la compara con
`version_compare()` contra `config('devices.min_app_version')`.

```php
Route::get('/expedientes', [ExpedienteController::class, 'index'])
    ->middleware('devices.version');
```

- **Es opt-in por ruta y NO está en el grupo `api`.** El día que se sube el
  mínimo, el login y el propio listado de dispositivos tienen que seguir
  respondiendo para que la app pueda decirle al usuario qué hacer. Por eso las
  rutas del módulo tampoco lo llevan puesto.
- **Sin cabecera se pasa.** Los clientes web no la mandan y no se actualizan por
  una tienda: no hay nada que exigirles. La cabecera es una declaración
  voluntaria del cliente que sí tiene ciclo de publicación.
- **Por debajo del mínimo, `426 Upgrade Required`**:

```json
{
  "error": {
    "code": "upgrade_required",
    "message": "Tu versión 1.9.9 ya no está soportada. Actualiza la aplicación a la 2.0.0 o superior para continuar."
  }
}
```

Este 426 pasa por `ApiExceptionRenderer` como cualquier otro error del
contrato (R54): el middleware lanza `App\Exceptions\UpgradeRequiredException`
con el mensaje ya formado y el renderer lo rinde con el caso
`ApiErrorCode::UpgradeRequired`. Sin `details`: en este contrato `details`
significa siempre «errores por campo» y es exclusiva del 422. Es el mismo camino
que `ConflictException` con el 409, y es el que hay que seguir si algún día hace
falta otro código: un caso en el enum y una excepción de dominio, nunca un JSON
escrito a mano en un middleware.

## Comando `devices:cleanup`

Tres pasos, en este orden y por una razón:

1. **Revocar los abandonados** — los activos que llevan `stale_after_days` sin
   aparecer (`last_seen_at`, o `created_at` si nunca volvieron). Un teléfono
   vendido, perdido o reinstalado guarda un token válido hasta que caduque, y
   ésa es la credencial que nadie va a echar de menos si se la roban.
2. **Borrar sus tokens de Sanctum** — los de los recién revocados, los de los
   que ya estaban revocados y los caducados que aún cuelgan de alguna fila. Los
   tokens que no son de ningún dispositivo se dejan a `sanctum:prune-expired`,
   que el scheduler ya corre a diario.
3. **Purgar los revocados antiguos** — los que llevan más de `prune_after_days`
   revocados. Pasado el plazo, la fila deja de ser auditoría y pasa a ser un dato
   personal guardado sin motivo.

Los dos relojes son distintos a propósito: primero se revoca por silencio y
**después** empieza a correr la retención, así que un dispositivo revocado hoy no
se borra hoy ni en la misma corrida.

```bash
php artisan devices:cleanup --dry-run   # cuenta lo mismo y no escribe nada
php artisan devices:cleanup
```

`--dry-run` lo aporta `App\Core\Console\Concerns\SupportsDryRun` sin declararlo
en la firma. Está programado a las **04:00**, detrás del backup de las 02:00: si
la purga se lleva algo que no debía, el zip de la noche todavía lo tiene. El
`Schedule::command()` va dentro de un `if` del toggle en `routes/console.php`,
porque con el toggle apagado el comando ni siquiera existe.

## `config/devices.php`

| Clave | Default | Para qué |
|-------|---------|----------|
| `min_app_version` | `0.0.0` (`DEVICES_MIN_APP_VERSION`) | el corte de `devices.version`; `0.0.0` no corta a nadie |
| `prune_after_days` | `90` | retención de un dispositivo revocado |
| `stale_after_days` | `180` | inactividad tras la que se da por abandonado |
| `platforms` | `['ios', 'android', 'web', 'cli']` | lista blanca; subconjunto de `Enums\Platform` |

Es a `kore-app.devices.enabled` lo que `config/kore-api.php` es a
`kore-app.api.enabled`: el toggle dice **si** el módulo existe, este archivo dice
**cómo se comporta** cuando existe. Y como todo `config/*.php`, no lee ningún
otro (R12).

## Tests

`app/Modules/Devices/Tests/Feature/`, 54 tests:

| Archivo | Qué cubre |
|---------|-----------|
| `DevicesToggleTest` | R10: apagado no hay rutas, ni alias, ni comando, ni scheduler, ni listeners — y la tabla **sí** existe. Encendido, todo lo anterior. Más el caso módulo ON + API OFF |
| `DeviceListenersTest` | los dos eventos de Auth con cada combinación: con y sin `deviceId`, plataforma fuera de la lista blanca, segundo login, dispositivo resucitado, dos usuarios en el mismo aparato, `tokenId` nulo |
| `DevicesApiTest` | los tres endpoints: 401 sin token, listado propio, campos exactos del resource, `current`, 204 + token borrado, el token de la propia petición, 404 ajeno, 422 con `details`, y que el push token no sale nunca |
| `EnsureClientVersionTest` | 426 con el shape del contrato y sin `details`; sin cabecera, vacía, igual y superior pasan |
| `DevicesCleanupCommandTest` | `--dry-run` cuenta y no escribe; la corrida revoca, borra tokens y purga; los dos plazos; el token caducado |
| `DevicesConfigTest` | `platforms` ⊆ `Platform`, los dos relojes en orden y el mínimo por defecto |

Dos de esos archivos —`DevicesApiTest` y `DevicesCleanupCommandTest`— encienden
el módulo registrando el provider a mano (`app()->register(..., force: true)`) en
vez de con `withEnvironment()`. No es pereza: `withEnvironment()` rearranca la
aplicación, y `RefreshDatabase` deja abierta una transacción sobre el PDO en
memoria que la conexión nueva ya no contabiliza (`Connection::setPdo()` pone el
nivel a 0). El primer `DB::transaction()` de dentro —el de `DeviceRevokeAction` y
el del comando— intenta un `BEGIN` sobre una conexión que ya está en transacción
y revienta. Que el toggle encienda y apague de verdad lo prueba
`DevicesToggleTest`, que sí usa `withEnvironment()` porque allí no hay
transacciones. Ver [`../patterns/test-con-otro-entorno.md`](../patterns/test-con-otro-entorno.md).

## Qué falta

- **Un derivado con su propio login por token** —en el boilerplate los dispara
  Auth (`AuthApiTokenIssueAction` y `AuthApiTokenRevokeAction`)— sólo tiene que
  publicar los dos eventos de `App\Modules\Auth\Events` con los datos del
  cliente; no hace falta llamar a ninguna Action de Devices.

## Reglas relacionadas

- **[R5](../architecture/rules.md)** — los eventos como única frontera con Auth.
- **[R10](../architecture/rules.md) · [R11](../architecture/rules.md)** — el
  toggle apagado no registra nada, y existe porque alguien lo lee.
- **[R54](../architecture/rules.md)** — el contrato de la API: el 426 también
  pasa por el renderer, vía `UpgradeRequiredException`.
- **[R29](../architecture/rules.md)** — la migración define `down()`.
- **[R25](../architecture/rules.md)** — la Policy como punto de decisión, con la
  consulta acotada por dueño delante.
