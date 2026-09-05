# Módulo Webhooks

**TL;DR**: webhooks salientes fiables detrás de `WEBHOOKS_ENABLED`. Publicar
**no manda nada**: escribe una fila en un outbox dentro de la transacción de
quien publica, y la entrega la hace después un listener en cola con firma
HMAC-SHA256 y reintentos con backoff. Trae también el verificador para el lado
que **recibe** (`webhook.signed`) y una pantalla en `/webhooks` para administrar
los suscriptores y ver qué pasó con cada entrega.

- Contrato: `App\Core\Contracts\WebhookPublisher`
- Firma (los dos lados): `App\Core\Support\WebhookSignature`
- Toggle: `WEBHOOKS_ENABLED` (`kore-app.webhooks.enabled`), por defecto `false`
- Parámetros: `config/kore-webhooks.php`
- Permiso: `webhooks.manage`

## El toggle

Con `WEBHOOKS_ENABLED=false` el provider hace `return` y no registra **nada
observable** (R10): ni el binding de `WebhookPublisher`, ni las rutas
`/webhooks`, ni la policy, ni el alias `webhook.signed`, ni los listeners, ni
`webhooks:dispatch` / `webhooks:prune`, ni sus entradas en el scheduler, ni sus
traducciones. Resolver el contrato lanza `BindingResolutionException` —«esta
instalación no manda webhooks»—, que es mejor respuesta que un envío silencioso
a ninguna parte. Por eso quien lo consume pregunta antes:

```php
if ((bool) config('kore-app.webhooks.enabled')) {
    resolve(WebhookPublisher::class)->publish('orders.created', $data);
}
```

Dos cosas se registran **siempre**, y son las excepciones documentadas de R10:

- **Las tablas.** `webhook_endpoints` y `webhook_deliveries` se migran con el
  toggle apagado. Un toggle apaga rutas y comportamiento, nunca la forma de la
  base: si la migración fuera condicional, dos instalaciones del mismo commit
  tendrían bases distintas según el `.env` del día en que se migró, y encender
  el toggle en producción exigiría una migración a mano con tráfico encima. El
  precedente es `AUTH_PASSKEYS=false`.
- **El espacio de vistas `webhooks::`**, por lo mismo que en Files: Blade
  resuelve las etiquetas de componente al **compilar** la plantilla, no al
  ejecutarla, así que un `@if` alrededor no evita nada. Un espacio de vistas sin
  rutas que lo pinten no expone nada.

## Por qué un outbox y no una llamada HTTP

`WebhookPublisher::publish()` **no hace ninguna petición**. Escribe una fila por
endpoint suscrito y dispara `WebhookDeliveryQueued`; el listener en cola es el
que entrega.

La clave es que `publish()` corre **dentro de la transacción de quien publica**:

```php
DB::transaction(function () use ($data) {
    $order = $this->orders->create($data);

    resolve(WebhookPublisher::class)->publish('orders.created', $order->toArray());
});
```

- Si la transacción se **revierte**, las filas del outbox se van con ella: no
  sale ningún webhook contando un pedido que no existe.
- Si **confirma**, las filas están escritas y la entrega ocurrirá: hoy por el
  listener, o mañana por el barrido de `webhooks:dispatch` si la cola estuvo
  parada.

Una llamada HTTP en ese punto no puede dar ninguna de las dos garantías —no se
«deshace» una petición ya enviada— y ataría el tiempo de respuesta del usuario
al servidor de un tercero (R22).

El listener lleva `$afterCommit = true`, así que el trabajo no se despacha hasta
que la transacción confirma: sin eso, un worker rápido podría leer la fila antes
de que exista para él.

## Publicar un evento propio

Tres pasos:

1. **Declara el evento** en `config/kore-webhooks.php` → `events`, con su
   descripción en español. El convenio de nombres es
   `{dominio}.{recurso}.{verbo en pasado}`.

   ```php
   'events' => [
       'auth.api_token.issued' => 'Se emitió un token de API para un usuario',
       'orders.created' => 'Se creó un pedido',
   ],
   ```

   Ese catálogo manda dos cosas: lo que el selector del formulario ofrece, y qué
   acepta `publish()`. Un nombre que no esté ahí lanza `InvalidArgumentException`
   — un evento mal escrito no puede fallar en silencio, porque el suscriptor no
   recibiría nada y nadie se enteraría hasta que lo reclamara.

2. **Publica desde tu Action**, dentro de su transacción y detrás del toggle
   (ver arriba). El payload va **sin secretos**: lo que se pase acaba en un
   servidor de terceros.

3. **Ya está.** El resto —firmar, reintentar, purgar, la pantalla— es de este
   módulo.

El ejemplo ejecutable en el propio boilerplate es
`App\Modules\Webhooks\Listeners\PublishApiTokenIssued`: escucha
`App\Modules\Auth\Events\ApiTokenIssued` —la frontera pública de Auth (R5)— y
publica `auth.api_token.issued` con el id y el correo del usuario, el nombre del
token y de qué cliente venía. Nunca el token.

## La firma

Cada entrega sale con estas cabeceras:

```
X-Kore-Signature: t=1772668800,v1=6f1c…
X-Kore-Event: orders.created
X-Kore-Delivery: 0f3a…-…-…
Content-Type: application/json
User-Agent: kore-laravel-webhooks/1
```

- Se firma `"{timestamp}.{body}"` con HMAC-SHA256 y el secreto del endpoint,
  donde `body` es el JSON **tal cual viaja**, byte a byte. No se firma un array
  reserializado: dos serializaciones del mismo array pueden diferir en el orden
  de las claves o en el escape de una barra, y entonces la firma no cuadra.
- El timestamp entra **en la carga**, no sólo en la cabecera. Si no, cambiarlo
  no invalidaría la firma y la ventana temporal no serviría de nada.
- `v1` es el número de esquema: el día que haya un `v2`, la cabecera puede
  llevar los dos y cada receptor verifica el que entienda.
- `X-Kore-Delivery` es el mismo uuid en todos los reintentos. Es lo que permite
  al receptor **ser idempotente** cuando un 2xx se pierde por el camino y
  volvemos a llamar.

El cuerpo:

```json
{
  "id": "0f3a…",
  "event": "orders.created",
  "created_at": "2026-09-05T10:00:00+00:00",
  "attempt": 1,
  "data": { }
}
```

### Verificarla en el receptor

**En PHP** (otra instalación de kore no necesita nada: le basta el middleware
`webhook.signed`, ver abajo). Desde cualquier otra aplicación:

```php
$secreto = getenv('KORE_WEBHOOK_SECRET');
$cuerpo = file_get_contents('php://input');

[$t, $v1] = [null, null];
foreach (explode(',', $_SERVER['HTTP_X_KORE_SIGNATURE'] ?? '') as $parte) {
    [$clave, $valor] = array_pad(explode('=', trim($parte), 2), 2, null);
    if ($clave === 't') { $t = $valor; }
    if ($clave === 'v1') { $v1 = $valor; }
}

if ($t === null || $v1 === null || abs(time() - (int) $t) > 300) {
    http_response_code(401);
    exit;
}

$esperada = hash_hmac('sha256', $t.'.'.$cuerpo, $secreto);

if (! hash_equals($esperada, $v1)) {
    http_response_code(401);
    exit;
}
```

**En Node**:

```js
import crypto from 'node:crypto';

// El cuerpo CRUDO, no el objeto ya parseado:
// express.raw({ type: 'application/json' })
export function verificar(secreto, cabecera, cuerpo) {
    const partes = Object.fromEntries(
        cabecera.split(',').map((p) => p.trim().split('=')),
    );

    if (!partes.t || !partes.v1) return false;
    if (Math.abs(Date.now() / 1000 - Number(partes.t)) > 300) return false;

    const esperada = crypto
        .createHmac('sha256', secreto)
        .update(`${partes.t}.${cuerpo}`)
        .digest('hex');

    return crypto.timingSafeEqual(Buffer.from(esperada), Buffer.from(partes.v1));
}
```

Las tres cosas que no son opcionales en ninguno de los dos: el **cuerpo crudo**,
la **ventana temporal** y una comparación en **tiempo constante**
(`hash_equals` / `timingSafeEqual`). Un `===` sobre cadenas corta en el primer
byte distinto, y ese tiempo es medible desde fuera.

Y una cuarta que la firma no da: **deduplica por `X-Kore-Delivery`.** Una
petición bien firmada puede llegarte más de una vez, y no hace falta que nadie
la capture para reenviarla: si tu 2xx se pierde por el camino, el emisor
reintenta con **el mismo uuid, el mismo cuerpo y una firma nueva y válida**.
La ventana de cinco minutos acota cuánto vale una firma capturada, pero no
convierte la entrega en única. Guarda el uuid de lo que ya procesaste y contesta
2xx sin volver a aplicarlo:

```php
if ($yaProcesado($_SERVER['HTTP_X_KORE_DELIVERY'])) {
    http_response_code(200);   // idempotente: ya está hecho
    exit;
}
```

## Recibir webhooks: el middleware `webhook.signed`

El boilerplate manda webhooks, no los recibe, así que **ninguna ruta suya lleva
este middleware**. Está para el derivado que sí los reciba —de otra instalación
de kore, o de un proveedor que use el mismo esquema—:

```php
// Routes/web.php o Routes/api.php de tu módulo
Route::post('/hooks/proveedor', ProveedorHookController::class)
    ->middleware('webhook.signed');
```

Lee el secreto de `kore-webhooks.inbound_secret` (`WEBHOOKS_INBOUND_SECRET`),
que **no tiene nada que ver** con los secretos de `webhook_endpoints`: aquéllos
son del lado emisor y viven cifrados en la base, uno por endpoint. Vacío
significa «esta instalación no recibe webhooks» y el middleware devuelve **404**
en vez de quedar abierto por omisión el día que alguien copie la ruta sin copiar
el `.env`. Una firma ausente, inválida o fuera de ventana es **401**: no es que
el emisor no tenga permiso, es que no ha demostrado ser quien dice.

## Reintentos

`WebhookDeliverAction` intenta la entrega **una vez** y decide qué pasa con el
resultado:

| Respuesta | Qué hace |
|-----------|----------|
| 2xx | `delivered`, apunta `response_status` y `delivered_at` |
| otro código | anota `HTTP {código}`, suma un intento y programa el siguiente |
| timeout / DNS / TLS | anota el mensaje, suma un intento y programa el siguiente |
| intentos agotados | `exhausted`, sin siguiente cita |
| endpoint desactivado o borrado | se cierra **sin gastar intentos**, con el motivo en `last_error` |

El backoff está en `config/kore-webhooks.php`: `[60, 300, 1800, 7200, 43200]`
segundos —1 m, 5 m, 30 m, 2 h, 12 h— con `max_attempts` en 6. Son seis intentos
repartidos en algo menos de quince horas: si el receptor no ha vuelto en ese
plazo, no es un corte de red sino una integración rota.

**La Action no lanza nunca**, y el listener va con `#[Tries(1)]`. Si además
reintentara la cola, habría dos relojes distintos sobre la misma fila y el
receptor recibiría ráfagas. Que el backoff viva en la base y no en la cola es
también lo que permite verlo, cambiarlo y reencolar a mano desde la pantalla.

### Los cuatro estados

| Estado | Qué significa |
|--------|---------------|
| `pending` | nunca se ha intentado, o se reencoló a mano; espera a `next_attempt_at` |
| `failed` | se intentó y falló, con reintento programado (transitorio) |
| `delivered` | el receptor contestó 2xx (final) |
| `exhausted` | se agotaron los intentos (final, salvo reintento manual) |

`pending` y `failed` son los dos que el barrido recoge. Están separados porque
«aún no se ha intentado» y «lleva tres intentos fallando» son cosas distintas
para quien mira la pantalla.

## Comandos

```bash
php artisan webhooks:dispatch            # entrega lo vencido (lo corre el scheduler cada minuto)
php artisan webhooks:dispatch --dry-run  # cuenta y no manda nada
php artisan webhooks:dispatch --limit=25 # tope de la pasada
php artisan webhooks:prune --days=30     # borra entregas cerradas más viejas
php artisan webhooks:prune --dry-run
```

`webhooks:dispatch` es la **red de seguridad** del outbox, no el camino normal:
en el camino normal la entrega sale por el listener en cola nada más confirmarse
la transacción, y esta pasada no encuentra nada. Existe para los reintentos con
backoff y para el día que los workers estuvieron parados. El tope de
`kore-webhooks.dispatch_batch` evita que una caída larga del receptor se
convierta en una ráfaga de diez mil peticiones cuando vuelve.

`webhooks:prune` sólo se lleva `delivered` y `exhausted`: lo que sigue en juego
no se toca, dure lo que dure su reintento. El corte se mide contra `created_at`
y no contra `delivered_at`, porque una entrega agotada no tiene fecha de entrega
y usar dos relojes según el estado haría que el mismo `--days=30` significara
cosas distintas en la misma tabla.

Los dos entran en `routes/console.php` bajo el toggle:
`webhooks:dispatch` cada minuto sin solapamiento, `webhooks:prune --days=30` a
las 04:45 —detrás del backup de las 02:00 y de las purgas de devices y files—.

## La pantalla

`/webhooks`, con permiso `webhooks.manage` (rol Administrador y superadmin; ver
[`../architecture/authorization.md`](../architecture/authorization.md)).

| Ruta | Qué es |
|------|--------|
| `GET /webhooks` | tabla de endpoints, con su estado y cuántas entregas tienen en cola |
| `GET /webhooks/create` | alta |
| `GET /webhooks/{endpoint:uuid}` | detalle: resumen por estado, últimas 50 entregas, payload y error de cada una, reintentar, rotar el secreto |
| `GET /webhooks/{endpoint:uuid}/edit` | edición |

**Un solo permiso y no el CRUD de cuatro.** Ver la lista ya enseña a qué
sistemas se les está contando lo que pasa aquí dentro, y quien la ve puede leer
el payload de cada entrega: no hay un «sólo lectura» menos sensible que el resto.

**El secreto se muestra UNA vez**, al crear y al rotar. Viaja por la sesión y no
como propiedad del componente: una propiedad pública viajaría en el snapshot de
Livewire en cada petición siguiente, así que el secreto se quedaría en el DOM
mucho después de que alguien lo copiara. En la recarga ya no está, y eso lo
comprueba `tests/e2e/specs/webhooks/crud.spec.ts`.

**Rotar corta en seco**: desde la siguiente entrega, el receptor que siga con la
clave vieja rechazará las firmas. No hay periodo de gracia con dos claves
válidas, porque una rotación se hace justamente cuando la anterior se considera
comprometida — dejarla viviendo una hora más sería no haber rotado.

### La URL no puede apuntar hacia dentro (SSRF)

Un emisor de webhooks es un cliente HTTP con la dirección elegida por el
usuario, que es la definición del problema: sin más, cualquiera con
`webhooks.manage` da de alta
`https://169.254.169.254/latest/meta-data/iam/security-credentials/` y lee las
credenciales del rol de la instancia en el cuerpo de un intento fallido, o
apunta a `https://127.0.0.1:9200/` y se asoma al Elasticsearch que no está
expuesto a Internet.

Lo cierra `Webhooks\Rules\PublicHttpUrl`, que **resuelve el host y mira las
direcciones**, no el nombre —bloquear la cadena `localhost` no sirve de nada
cuando `interno.ejemplo.com` es un registro A que apunta a `10.0.0.5`—. Rechaza
loopback (`127.0.0.0/8`, `::1`), link-local (`169.254.0.0/16`, `fe80::/10`),
privadas (`10/8`, `172.16/12`, `192.168/16`, `fc00::/7`), `0.0.0.0` y los hosts
que no resuelven a nada.

La comprobación va en **las dos** puertas —el Form Object y
`WebhookEndpointCreateAction` / `WebhookEndpointUpdateAction`—, con la
derivación en `Support\EndpointUrl` para no tener dos copias: las Actions sirven
igual desde un comando o un seeder, donde no hay validador que valga. Es el
mismo reparto que `Platform\Support\EditableSettings`.

**La válvula**: `WEBHOOKS_ALLOW_PRIVATE_NETWORKS=true`
(`kore-webhooks.allow_private_networks`) la desactiva entera, para la
instalación donde el receptor está legítimamente en la red interna. Va en
`false` por defecto **también en `local` y en `testing`**, a diferencia de
`WEBHOOKS_REQUIRE_HTTPS`: si se relajara sola en desarrollo, el único sitio
donde la regla se probaría de verdad sería producción. Los tests que necesitan
una URL interna encienden la clave a mano.

Lo que **no** cierra: el *DNS rebinding*. Entre la resolución de la validación y
la de la entrega, el dominio puede cambiar de respuesta. Cerrarlo pide resolver
y conectar contra la IP ya validada, que es cosa del cliente HTTP. Esto sube el
listón de «cualquiera con acceso a la pantalla» a «alguien que controla un
dominio y sabe lo que hace».

### Un aviso sobre las rutas

`permission:webhooks.manage` va **dentro del mismo array** que `web`, `auth` y
`verified`, no en un `->middleware()` encadenado aparte:
`RouteRegistrar::middleware()` **sustituye** el atributo en vez de acumularlo, y
un segundo encadenado se lleva por delante al grupo `web` entero —y con él
`SubstituteBindings`—. El síntoma no es un 403 ni un 404: es que el controller
recibe un modelo vacío y la pantalla revienta más abajo con un error que no
habla de rutas.

## El esquema

`webhook_endpoints`

| Columna | Notas |
|---------|-------|
| `uuid` | identidad pública (`HasPublicUuid` + `ROUTE_BY_UUID`); la PK sigue siendo entera |
| `name`, `url` | la URL no lleva índice: nadie busca por ella |
| `secret` | cast `encrypted`, y `#[Hidden]`: dos barreras para el mismo dato |
| `subscribed_events` | json; `["*"]` = todos, presentes y futuros. Se llama así y no `events` porque ése es un nombre reservado de Eloquent |
| `active` | un endpoint apagado no acumula cola |
| `created_by` | `nullOnDelete`: la integración sobrevive a quien la creó |

`webhook_deliveries`

| Columna | Notas |
|---------|-------|
| `uuid` | viaja en `X-Kore-Delivery`; el mismo en todos los reintentos |
| `endpoint_id` | `cascadeOnDelete`: borrar el endpoint se lleva sus entregas en una sentencia |
| `payload` | congelado al publicar, no una referencia: un reintento de mañana manda lo que pasó hoy |
| `attempts`, `status`, `next_attempt_at` | el índice `(status, next_attempt_at)` es lo que hace barato el barrido |
| `last_error` | truncado a `kore-webhooks.error_max_length`: un stack trace entero no se lee y sí pesa |
| `response_status` | el código HTTP tal cual, entero y sin truncar |

## Qué NO hace

- **No recibe** webhooks por sí solo: aporta el verificador, no la ruta.
- **No garantiza el orden.** Dos eventos del mismo recurso pueden llegar
  cambiados si el primero falló y el segundo no. El receptor tiene que
  ordenarlos por su propio criterio (el `created_at` del envelope) o ser
  idempotente.
- **No reintenta indefinidamente.** Pasados los seis intentos, la entrega queda
  `exhausted` y hay que reencolarla a mano desde la pantalla.
- **No firma con clave asimétrica.** El secreto es compartido, así que el
  receptor sabe que el mensaje viene de quien tiene la clave, no de quién en
  particular. Para el caso en que eso importe, la vía es un `v2` con firma
  asimétrica: el esquema versionado de la cabecera está puesto para eso.

## Ver también

- [`../architecture/toggles.md`](../architecture/toggles.md) — `WEBHOOKS_ENABLED`
- [`../architecture/authorization.md`](../architecture/authorization.md) — el permiso `webhooks.manage`
- [`devices.md`](devices.md) y [`files.md`](files.md) — los otros dos módulos con toggle y contrato en Core
