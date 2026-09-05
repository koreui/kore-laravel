# Módulo Notifications — la bandeja in-app

**TL;DR**: detrás de `NOTIFICATIONS_ENABLED` (apagado por defecto). Quien avisa
resuelve `App\Core\Contracts\Notifier` y le pasa un `NotificationData`; el módulo
decide por dónde sale cruzando el payload con las preferencias de esa persona, lo
guarda en la bandeja, lo enseña en la campana y en `/notifications`, lo publica en
`api/v1/me/notifications` y lo poda cuando envejece. **El canal de push sólo
escribe en el log**: el boilerplate no elige por ti entre FCM, APNs o Expo.

---

## Encenderlo

```dotenv
NOTIFICATIONS_ENABLED=true
```

Con el toggle apagado no hay **nada observable**: ni binding de `Notifier`, ni
rutas web o de API, ni campana, ni policies, ni listener, ni
`notifications:prune`, ni traducciones. Dos excepciones, que son la regla del
boilerplate y no válvulas:

- **Las tablas se migran siempre** (`notifications`, `notification_preferences`).
  Un toggle apaga rutas y comportamiento, nunca la forma de la base — si no,
  encenderlo en producción exigiría una migración a mano justo cuando ya hay
  tráfico. Mismo criterio que `devices`, `media` y `passkeys`.
- **El espacio de vistas `notifications::` se registra siempre**, porque Larastan
  valida `view('notifications::pages.index')` contra los namespaces registrados.
  Sin componentes Livewire registrados nadie puede montar esas vistas, así que no
  expone nada.

Los parámetros —categorías, poda, campana— viven en
[`config/kore-notifications.php`](../../config/kore-notifications.php), que no es
`kore-app` y no duplica el toggle: declara cifras, no capacidades (por eso el
check R11 no lo mira, y su equivalente es `NotificationsConfigTest`).

## El contrato: `App\Core\Contracts\Notifier`

```php
use App\Core\Contracts\Notifier;
use App\Core\Data\NotificationData;
use App\Core\Enums\NotificationCategory;

final readonly class NotifyOnInvoicePaid
{
    public function __construct(private Notifier $notifier) {}

    public function handle(InvoicePaid $event): void
    {
        $this->notifier->notify($event->userId, new NotificationData(
            title: __('Factura pagada'),
            body: __('Se registró el pago de :folio.', ['folio' => $event->folio]),
            category: NotificationCategory::Activity->value,
            url: '/invoices/'.$event->invoiceId,
            data: ['invoice_id' => $event->invoiceId],
        ));
    }
}
```

Dos métodos y nada más: `notify(int $userId, NotificationData)` y
`notifyMany(array $userIds, NotificationData)`. Quien avisa **no importa una sola
clase del módulo** (R5) y no sabe si el aviso acabó en la bandeja, en un correo o
en un push.

- **El destinatario es un `int`.** El contrato vive en Core, donde `auth()` está
  prohibido (R19): un id es lo único que un job serializado, un comando o un
  listener tienen siempre a mano.
- **Un destinatario que ya no existe no lanza.** Un aviso es el efecto secundario
  de algo que ya pasó; tumbar la operación original por el acuse de recibo sería
  cambiar el resultado por el recibo.
- **`notifyMany` quita los repetidos.** La misma persona puede llegar por dos
  caminos (es la autora *y* la responsable) y dos avisos idénticos son un fallo.
- **Con el toggle apagado no hay binding**: resolver `Notifier` lanza
  `BindingResolutionException` —«esta instalación no notifica»—, que es mejor
  respuesta que un aviso que desaparece en silencio. Por eso quien lo consume
  pregunta antes por `config('kore-app.notifications.enabled')`. Mismo criterio
  que `FileStore` y `PdfRenderer`.

### El DTO: `App\Core\Data\NotificationData`

| Campo | Tipo | Qué es |
|---|---|---|
| `title` | `string` | El titular. Es lo que se ve en la campana y el asunto del correo. |
| `body` | `string` | Una o dos frases. |
| `category` | `string` | Clave del catálogo. Por defecto, `system`. |
| `url` | `?string` | Ruta a la que lleva el aviso. `null` = no lleva a ningún sitio. |
| `data` | `array` | Ids y datos del caso, para quien los necesite. La bandeja no depende de su contenido. |
| `mail` | `bool` (true) | ¿Este aviso **puede** salir por correo? |
| `push` | `bool` (true) | ¿Este aviso **puede** salir por push? |

**`mail` y `push` son un techo, no una orden.** Dicen «este aviso admite ese
canal»; quien decide si sale de verdad es la preferencia de la persona. No hay
forma de saltársela desde el código que avisa, y ése es justamente el punto. El
canal `database` no tiene interruptor en el DTO porque es el canal base: un aviso
que no se guarda no existe.

## Categorías, y cómo las amplía un derivado

Una categoría es el área por la que alguien elige recibir o no recibir avisos. Se
pregunta por categoría y no por tipo de notificación porque nadie quiere una
pantalla con treinta interruptores, y porque añadir un aviso nuevo no puede
obligar a cada usuario a reconfigurar nada.

El boilerplate trae tres, y viven en **dos sitios a la vez, a propósito**:

| Clave | Etiqueta | Bandeja | Correo | Push | Para qué |
|---|---|---|---|---|---|
| `system` | Sistema | ✅ | ✅ | ❌ | Mantenimiento, versiones, cortes. |
| `account` | Cuenta | ✅ | ✅ | ❌ | Sesiones nuevas, seguridad, datos de la cuenta. |
| `activity` | Actividad | ✅ | ❌ | ❌ | Lo del trabajo del día: algo que se te asignó o cambió. |

- **`App\Core\Enums\NotificationCategory`** lista las tres del núcleo para que el
  código pueda citarlas con una constante (`NotificationCategory::Account->value`)
  y no con un literal suelto.
- **`config/kore-notifications.php` → `categories`** es el catálogo real: las
  etiquetas, los canales por defecto y **el sitio donde un derivado añade las
  suyas**.

La duplicidad no es un descuido: un enum de PHP no se extiende, así que si el
catálogo viviera sólo en Core un proyecto hijo tendría que editar el kernel para
tener una categoría propia. Por eso `NotificationData::$category` es un `string`.
Que las dos listas no se separen lo vigila `NotificationsConfigTest`.

**Para añadir una categoría en un derivado**, una clave más en el config:

```php
'categories' => [
    // ...las tres del boilerplate...
    'facturacion' => [
        'label' => 'Facturación',   // español fuente (R33); tradúcela en tu en.json
        'in_app' => true,
        'mail' => true,
        'push' => false,
    ],
],
```

Aparece sola en la pantalla de preferencias, en el filtro de la bandeja y en la
API. No hace falta migración ni seeder.

## Preferencias

Una fila por (usuario × categoría) en `notification_preferences`, con `unique`
sobre las dos columnas. **La ausencia de fila no significa «apagado»**: significa
«lo que diga el default de la categoría». Es lo que permite añadir una categoría
sin sembrar filas para toda la plantilla, y lo que hace que un usuario recién
creado reciba lo que tiene que recibir sin pasar por ningún seeder.

Quien resuelve esa mezcla es `Support\NotificationPreferences`, registrado como
`scoped()` —una instancia por petición— porque cachea: una corrida que avisa a
quince personas no debería consultar la misma fila quince veces.

La pantalla está en `/notifications/preferences` y la API en
`GET|PUT /api/v1/me/notification-preferences`.

## Canales

`Support\GenericNotification::via()` es donde se decide todo:

```
database  ← la preferencia in_app                          (canal base)
mail      ← la preferencia mail   Y  NotificationData::$mail
push      ← la preferencia push   Y  NotificationData::$push
```

Si los tres salen apagados, Laravel no manda nada y no falla: alguien que apagó
su categoría entera no recibe ese aviso, que es lo que pidió.

La clase de Notification y el canal viven en `Support/` y no en un
`Notifications/` o un `Channels/`: la lista de carpetas de un módulo es cerrada
(R3) y ésa es la carpeta que R3 reserva a los adaptadores de paquete. Misma
decisión que puso `MediaFileStore` en `Files/Support`.

### El push sólo escribe en el log

`Support\PushChannel` no manda nada a ningún servicio: deja una línea
`notifications.push` en el log con el usuario, **cuántos** tokens había (nunca
cuáles: un token de push es la credencial con la que se le manda una notificación
a ese teléfono), la categoría, el título y la URL.

**El `body` no se registra**, y el título sí: el título es una etiqueta de la
plantilla («Factura vencida»), mientras que el cuerpo es donde van el nombre, el
importe o el número de expediente. Los logs se rotan, se mandan a un agregador y
se leen con mucha más manga ancha que la bandeja de avisos, así que ahí queda
qué se mandó y a cuántos aparatos, no lo que decía.

No es un descuido. El boilerplate no puede elegir por un derivado entre FCM, APNs
o Expo, y fingir una entrega que no ocurre es peor que no tenerla — un aviso que
se da por entregado y nunca llegó no lo descubre nadie. Lo que **sí** está
resuelto es todo lo demás: las preferencias, la elección del canal y de dónde
salen los tokens.

**Para enchufar un servicio real**, sustituye el `Log::info()` por la llamada al
proveedor, con los `$tokens` que ya vienen resueltos:

```php
Http::withToken(config('services.fcm.key'))
    ->post('https://fcm.googleapis.com/fcm/send', [
        'registration_ids' => $tokens,
        'notification' => ['title' => $payload->title, 'body' => $payload->body],
    ]);
```

Un canal **sí** puede hacer E/S remota: R22 prohíbe la llamada externa en la capa
de entrega (controllers y Livewire), y esto corre por debajo.

### De dónde salen los tokens: `PushTokenDirectory`

Los tokens de push **no** viven en este módulo: viven en la columna `push_token`
de la tabla `devices`, que mantiene el módulo **Devices**. Para llegar a ellos sin
importar una sola clase de Devices (R5) hay un contrato en Core:

```php
namespace App\Core\Contracts;

interface PushTokenDirectory
{
    /** @return array<int, string> */
    public function tokensFor(int $userId): array;
}
```

- Lo implementa `App\Modules\Devices\Support\DevicePushTokens`, que devuelve los
  tokens de los dispositivos **activos** (un revocado es un teléfono vendido o
  perdido) y **sin repetir** (reinstalar la app puede devolver el mismo token).
- El binding lo pone `DevicesModuleServiceProvider::register()` y **sólo con
  `DEVICES_ENABLED=true`**.
- `PushChannel` pregunta antes por `bound()` en vez de resolverlo: sin inventario
  de dispositivos no hay a dónde mandar un push, y eso no puede tumbar un aviso
  que ya está en la bandeja. Lo deja dicho en el log, una vez, con el motivo.
- La pantalla de preferencias esconde el interruptor de push cuando el módulo
  Devices está apagado: ofrecer un interruptor que no hace nada es prometer algo
  que no ocurre.

Toda la relación entre los dos módulos son esa interfaz y veinte líneas de
implementación.

## La bandeja y la campana

| Pantalla | Ruta | Componente |
|---|---|---|
| Bandeja | `GET /notifications` (`notifications.index`) | `TableNotifications` |
| Preferencias | `GET /notifications/preferences` (`notifications.preferences`) | `NotificationSettings` |
| Campana | — (la pinta el layout) | `NotificationBell` |

- **Sin permisos propios.** `auth` + `verified` y nada más: una bandeja no es una
  sección a la que se dé acceso, es algo que todo el mundo tiene. Lo que hay que
  decidir no es «¿puede entrar?» sino «¿es suya?», y eso lo responde
  `NotificationPolicy` dentro de cada componente (R23 · R25).
- **La campana la inserta el layout** `resources/views/components/layouts/app.blade.php`
  detrás de `config('kore-app.notifications.enabled')`: con el toggle apagado el
  componente `notifications.bell` ni siquiera está registrado, así que pintarlo
  sería un error de Livewire y no un hueco vacío.
- **`wire:poll` cada `kore-notifications.bell.poll_seconds`** (30 por defecto).
  Está en el config porque no es gratis: son tantas consultas por minuto como
  pestañas abiertas haya. Un derivado con websockets lo pone a `0` y refresca por
  el evento `notifications-updated`, que la campana ya escucha.
- **A la Blade llegan arrays, no modelos** (R30): título, cuerpo, etiqueta de la
  categoría y el «hace tres horas» salen resueltos de
  `Support\NotificationPresenter`, que es también lo que evita tener el mismo
  mapeo dos veces (campana y bandeja).

## La API

Todo cuelga de `me`: una notificación es de quien la recibe y nadie consulta la
de otro. Se carga con los **dos** toggles encendidos (`NOTIFICATIONS_ENABLED` y
`API_ENABLED`), y sigue el contrato de R54.

| Método | Ruta | Nombre |
|---|---|---|
| `GET` | `/api/v1/me/notifications` | `api.v1.me.notifications.index` |
| `POST` | `/api/v1/me/notifications/read-all` | `api.v1.me.notifications.read-all` |
| `POST` | `/api/v1/me/notifications/{notification}/read` | `api.v1.me.notifications.read` |
| `GET` | `/api/v1/me/notification-preferences` | `api.v1.me.notification-preferences.index` |
| `PUT` | `/api/v1/me/notification-preferences` | `api.v1.me.notification-preferences.update` |

- **Sin `abilities:`**, a diferencia de `api/v1/users`: las abilities de un token
  son los permisos de su dueño, y aquí no hay permiso que pedir. Lo que decide es
  de quién es la fila — el ámbito lo pone la relación y encima la Policy vuelve a
  comprobarla.
- El listado pagina **por cursor** (`?per_page=`, `?cursor=`) y admite `?unread=1`
  y `?category=`. `meta.unread_count` viaja en cada respuesta para que la app
  pinte el globo sin una segunda llamada.
- Una notificación que no es tuya da **404 y no 403**: decir «existe pero no es
  tuya» confirmaría el uuid a quien lo estaba probando.
- **El payload del aviso se publica como `payload`, no como `data`.** No es
  cosmético: `data` es el sobre del contrato, y un recurso que declara un campo
  con ese nombre hace que `ResourceResponse::wrap()` lo confunda con el sobre ya
  puesto y devuelva la notificación **sin envelope**, con `meta` colgando al lado.
- `meta.push_available` dice si tiene sentido enseñar el interruptor de push.

## La poda

```bash
php artisan notifications:prune               # kore-notifications.prune_days (90)
php artisan notifications:prune --days=30
php artisan notifications:prune --dry-run     # cuenta y no escribe nada
```

En el scheduler a las **04:45**, detrás del backup de las 02:00 y de las purgas de
devices y files: si se lleva algo que hacía falta, el zip de la noche todavía lo
tiene.

**Sólo se van las leídas.** Las no leídas no caducan por edad: si nadie las vio,
borrarlas es perder el aviso. Y el plazo se cuenta desde `read_at` y no desde
`created_at`: lo que caduca es el aviso ya atendido, no el aviso viejo.

## El listener que enseña la frontera (R5)

`Listeners\NotifyOnApiTokenIssued` escucha `App\Modules\Auth\Events\ApiTokenIssued`
y avisa a quien acaba de emitir un token: alguien entró con tu cuenta desde un
cliente de API, y si no fuiste tú tienes dónde verlo.

Va en la categoría `account` —cuyo default trae el correo encendido, porque un
inicio de sesión que sólo se ve entrando es un aviso que llega tarde— y con
`push: false`, porque avisar al mismo teléfono que acaba de entrar es ruido.

Es también el ejemplo vivo de R5: `App\Modules\Auth\Events\*` es la única parte
de Auth que este módulo importa, Auth no sabe que Notifications existe, y Devices
escucha **el mismo evento** sin que ninguno de los dos conozca al otro.

## Archivos

```
app/Core/
├── Contracts/Notifier.php              # avisar a alguien
├── Contracts/PushTokenDirectory.php    # dónde están los tokens de push
├── Data/NotificationData.php           # la forma de cualquier aviso
└── Enums/NotificationCategory.php      # las tres del núcleo

app/Modules/Notifications/
├── Actions/                            # Send · MarkRead · MarkAllRead · PreferenceUpdate · Prune
├── Console/Commands/NotificationsPruneCommand.php
├── Data/NotificationPreferenceData.php
├── Database/{Migrations,Factories}/
├── Http/
│   ├── Controllers/NotificationsController.php
│   ├── Controllers/Api/V1/{Notification,NotificationPreference}Controller.php
│   ├── Livewire/{NotificationBell,TableNotifications,NotificationSettings}.php
│   ├── Requests/Api/V1/NotificationPreferenceUpdateRequest.php
│   └── Resources/Api/V1/{Notification,NotificationPreference}Resource.php
├── Listeners/NotifyOnApiTokenIssued.php
├── Models/NotificationPreference.php
├── Policies/{Notification,NotificationPreference}Policy.php
├── Providers/NotificationsModuleServiceProvider.php
├── Resources/{views,lang}/
├── Routes/{web,api}.php
├── Support/                            # DatabaseNotifier · GenericNotification · PushChannel
│                                       # NotificationCategories · NotificationPreferences · NotificationPresenter
└── Tests/Feature/

app/Modules/Devices/Support/DevicePushTokens.php   # la implementación de PushTokenDirectory
config/kore-notifications.php
```

## Tests

- `NotificationsToggleTest` — el contrato del toggle: OFF/ON, las dos excepciones
  (esquema y namespace de vistas), la API detrás de sus dos toggles.
- `NotificationsConfigTest` — el catálogo del config contra el enum de Core.
- `NotificationActionsTest` — las cinco Actions.
- `GenericNotificationTest` — `via()` canal a canal, con y sin preferencia guardada.
- `PushChannelTest` — que loguea, que **no** loguea los tokens y que no envía sin
  directorio.
- `NotifyOnApiTokenIssuedTest` — el listener y la forma de su aviso.
- `NotificationsLivewireTest` — los tres componentes, incluida la notificación
  ajena que no se puede marcar.
- `NotificationsApiTest` — el contrato de R54 endpoint a endpoint.
- `NotificationsPruneCommandTest` — qué borra, qué no y el ensayo.
- `Devices\Tests\Feature\DevicePushTokensTest` — el directorio y su binding.
- E2E: `tests/e2e/specs/notifications/{smoke,inbox,preferences}.spec.ts`, con
  `NOTIFICATIONS_ENABLED=true` en `.env.e2e` y las dos rutas en el mapa de acceso
  (R52). La notificación la siembra el harness (`POST /__e2e__/notify`), que pasa
  por el mismo `Notifier` que la aplicación.

## Ver también

- [`../architecture/toggles.md`](../architecture/toggles.md) — el patrón del provider con `return` temprano
- [`devices.md`](devices.md) — el inventario de dispositivos y su `push_token`
- [`../guides/api.md`](../guides/api.md) — el contrato de la API (R54)
- [`../quality/e2e.md`](../quality/e2e.md) — el harness y sus endpoints
