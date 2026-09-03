# Observabilidad

**TL;DR**: cuatro herramientas instaladas listas para enchufar en producción: **Sentry** (errores), **Pulse** (performance interno), **spatie/laravel-health** (health checks `/health`), **spatie/laravel-activitylog** (audit log opt-in por modelo).

## Resumen

| Tool                       | Endpoint                | Activación                                      | Acceso                                        |
|----------------------------|-------------------------|-------------------------------------------------|-----------------------------------------------|
| Sentry                     | —                       | `SENTRY_LARAVEL_DSN` en `.env`                   | —                                             |
| Laravel Pulse              | `/pulse`                | `PULSE_ENABLED=true` (default `false`)           | `auth` + gate `viewPulse` → sólo superadmin    |
| spatie/laravel-health      | `/health`, `/health/json` | siempre (`HealthServiceProvider`)              | HTML: `auth` + gate `viewHealth`; JSON: token   |
| spatie/laravel-activitylog | —                       | trait `LogsActivity` por modelo                  | ya activo en `User` y `Role`                   |

## 1. Sentry

```env
SENTRY_LARAVEL_DSN=https://xxxxx@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
```

**Cómo se activa de verdad** (dos piezas, ambas necesarias):

1. **DSN**: sin `SENTRY_LARAVEL_DSN` el SDK queda inactivo y no envía nada.
2. **Handler de excepciones**: `bootstrap/app.php` llama a
   `\Sentry\Laravel\Integration::handles($exceptions)` dentro de
   `withExceptions()`. Sin esa línea las excepciones no capturadas **no llegan
   a Sentry** aunque el DSN esté puesto (`withExceptions()` estaba vacío hasta
   la v1.0.0).

**Canal de log opcional** — `config/logging.php` declara un canal `sentry`
(driver `sentry`, nivel `LOG_SENTRY_LEVEL`, default `error`). No está en el
stack por defecto; para enviar también los logs en producción:

```env
LOG_STACK=single,sentry
LOG_SENTRY_LEVEL=error
```

Otras notas:

- Release tracking: el `Dockerfile` recibe `--build-arg GIT_SHA=$(git rev-parse --short HEAD)` y lo expone como `SENTRY_RELEASE` para correlacionar errores con commits.
- Config: `config/sentry.php` (publicado).
- `trustProxies` está configurado en `bootstrap/app.php`: sin él, la IP que
  Sentry adjunta a cada evento sería siempre la interna del contenedor.

Capturar manualmente en Actions:

```php
try {
    $action->handle($data);
} catch (\Throwable $e) {
    \Sentry\Laravel\Integration::captureUnhandledException($e);
    throw $e;
}
```

## 2. Laravel Pulse

```env
PULSE_ENABLED=false   # default; ponlo en true sólo donde lo quieras grabar
```

**Autorización**: el gate `viewPulse` está definido en
`AuthModuleServiceProvider::registerObservabilityGates()` y sólo deja pasar al
rol `superadmin`. Pulse trae su propio `viewPulse` (que permitiría el entorno
`local` a cualquiera); como los providers de paquete arrancan antes que los de
la app, la definición del boilerplate gana.

> Las rutas de Pulse se registran aunque `PULSE_ENABLED=false`; el toggle sólo
> apaga los *recorders*. Por eso el gate importa siempre.

Los **diez** recorders que registra `config/pulse.php` (`php artisan tinker
--execute 'print_r(array_keys(config("pulse.recorders")));'` los lista):

| Recorder | Qué graba |
|----------|-----------|
| `CacheInteractions` | hits y misses de caché |
| `Exceptions` | excepciones por clase y ubicación |
| `Queues` | throughput de las colas (encolados, procesados, fallidos) |
| `Servers` | CPU, memoria y disco del servidor (necesita `pulse:check`) |
| `SlowJobs` | jobs por encima del umbral |
| `SlowOutgoingRequests` | llamadas HTTP salientes lentas |
| `SlowQueries` | consultas por encima del umbral |
| `SlowRequests` | peticiones lentas |
| `UserJobs` | qué usuario despacha más jobs |
| `UserRequests` | qué usuario hace más peticiones |

`Servers` es el único que necesita un proceso aparte (`php artisan pulse:check`);
el resto graba desde la propia petición. Los umbrales de los `Slow*` y los
`ignore` de cada uno se editan en `config/pulse.php`.

Cron para `pulse:check` y `pulse:work` (no están en el scheduler del
boilerplate porque sólo aplican con Pulse encendido):

```bash
* * * * * cd /opt/kore-laravel && php artisan pulse:check >> /dev/null 2>&1
* * * * * cd /opt/kore-laravel && php artisan pulse:work >> /dev/null 2>&1
```

## 3. spatie/laravel-health

`app/Providers/HealthServiceProvider.php` registra los checks por defecto:

```php
Health::checks([
    DatabaseCheck::new(),
    CacheCheck::new(),
    UsedDiskSpaceCheck::new()
        ->warnWhenUsedSpaceIsAbovePercentage(70)
        ->failWhenUsedSpaceIsAbovePercentage(90),
    ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
    OptimizedAppCheck::new(),
]);
```

### Endpoints

El paquete **no** publica rutas por su cuenta: las registra
`HealthServiceProvider::registerRoutes()`.

| Ruta           | Para          | Protección                                                     |
|----------------|---------------|----------------------------------------------------------------|
| `/health`      | humanos (HTML)| `web` + `auth` + `can:viewHealth` (gate → sólo superadmin)      |
| `/health/json` | monitores     | middleware `RequiresSecretToken` → header `X-Secret-Token`      |

```env
# config/health.php → secret_token
HEALTH_SECRET_TOKEN=un-token-largo-y-aleatorio
```

> ⚠️ El middleware del paquete **deja pasar todo si el token está vacío**. En
> producción `HEALTH_SECRET_TOKEN` es obligatorio.

```bash
curl -H "X-Secret-Token: $HEALTH_SECRET_TOKEN" https://tu-dominio/health/json
```

`?fresh=1` fuerza a re-ejecutar los checks en vez de servir el último resultado
almacenado.

### Almacenamiento

Los resultados van a la tabla `health_check_result_history_items`
(`EloquentHealthResultStore`). La migración está publicada en
`database/migrations/*_create_health_tables.php`.

### Scheduler

`routes/console.php` programa (ver sección «Scheduler» más abajo):

- `health:check` — cada minuto, genera los resultados que sirven ambos endpoints
- `health:schedule-check-heartbeat` — cada minuto, el latido que consume `ScheduleCheck`

Con un único cron en el servidor (o el servicio `scheduler` de
`docker-compose.prod.yml`, que ahora sí tiene trabajo que hacer):

```bash
* * * * * cd /opt/kore-laravel && php artisan schedule:run >> /dev/null 2>&1
```

Agregar checks: edita `HealthServiceProvider::registerChecks()` o crea un check custom (`spatie/laravel-health/v1/available-checks`).

Notificaciones a Slack cuando un check falla:

```php
Health::notifications()->slackWebhookUrl(env('HEALTH_SLACK_WEBHOOK'));
```

## 4. spatie/laravel-activitylog

Tabla `activity_log` ya está creada. **Ya está en uso**: `App\Models\User` y
`App\Modules\Auth\Models\Role` traen el trait, así que altas, cambios de
nombre/email y cambios de rol quedan auditados con su `causer`.

> El paquete es la v5: el trait vive en
> `Spatie\Activitylog\Models\Concerns\LogsActivity` y `LogOptions` en
> `Spatie\Activitylog\Support\LogOptions` (en v4 estaban en `Traits\` y en
> la raíz). El método `dontSubmitEmptyLogs()` se llama ahora
> `dontLogEmptyChanges()`, y los cambios se leen de `attribute_changes`, no de
> `properties`.

Para auditar otro modelo:

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class Order extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total'])   // nunca password ni secretos
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

Consultar:

```php
$order->activities;                        // colección de logs
$activity->attribute_changes['attributes']; // valores nuevos
$activity->attribute_changes['old'];        // valores anteriores
$activity->causer;                          // quién lo hizo

activity()
    ->causedBy($user)
    ->performedOn($order)
    ->log('cancelled');        // log manual
```

Purga: `activitylog:clean` corre a diario desde el scheduler. La retención es
`activitylog.clean_after_days`, **365 días** por defecto. El boilerplate no
publica `config/activitylog.php`: el valor lo registra el propio paquete, así
que el comando funciona tal cual. Para cambiarlo, o `--days=N` en el schedule, o
publica el config con `php artisan vendor:publish --tag=activitylog-config` y
edítalo.

## 5. Scheduler

`routes/console.php` concentra todas las tareas programadas:

| Comando                                | Frecuencia | Por qué                                        |
|----------------------------------------|------------|------------------------------------------------|
| `health:check`                         | cada minuto| genera los resultados de `/health`             |
| `health:schedule-check-heartbeat`      | cada minuto| latido que consume `ScheduleCheck`             |
| `queue:prune-batches`                  | diario     | limpia batches viejos                          |
| `queue:prune-failed --hours=168`       | diario     | limpia jobs fallidos de más de 7 días          |
| `activitylog:clean`                    | diario     | purga el audit log                             |
| `sanctum:prune-expired --hours=24`     | diario     | sólo si `API_ENABLED=true`                     |

`model:prune` está comentado a propósito: hoy ningún modelo usa `Prunable`.

Verificar en cualquier momento:

```bash
php artisan schedule:list
```

## Logs estructurados

Para JSON logs en producción, edita `config/logging.php`:

```php
'production' => [
    'driver'   => 'stack',
    'channels' => ['stderr_json'],
],
'stderr_json' => [
    'driver'    => 'monolog',
    'handler'   => Monolog\Handler\StreamHandler::class,
    'with'      => ['stream' => 'php://stderr'],
    'formatter' => Monolog\Formatter\JsonFormatter::class,
],
```

Y en `.env` de producción:

```env
LOG_CHANNEL=production
LOG_LEVEL=error
```

## Tests

La observabilidad tiene tres suites propias en `tests/Feature/`, 21 tests en
total (`./vendor/bin/pest tests/Feature/HealthTest.php tests/Feature/PulseAccessTest.php tests/Feature/SentryIntegrationTest.php`):

| Archivo | Qué cubre | Tests |
|---------|-----------|-------|
| `HealthTest` | los dos endpoints, el gate `viewHealth`, el token de `/health/json` y los checks registrados | 12 |
| `PulseAccessTest` | el gate `viewPulse` (sólo superadmin) y que las rutas existan con el toggle apagado | 5 |
| `SentryIntegrationTest` | que `Integration::handles()` esté enganchado y el canal de log `sentry` declarado | 4 |

`PulseAccessTest` renderiza el dashboard de Pulse, que arrastra su bundle JS: por
eso `phpunit.xml` fija `memory_limit=512M`. Con el 128M de algunas instalaciones
de PHP la suite se cae por memoria, no por el test.

## Recursos

- Sentry Laravel: https://docs.sentry.io/platforms/php/guides/laravel/
- Pulse: https://laravel.com/docs/12.x/pulse
- spatie/laravel-health: https://spatie.be/docs/laravel-health/v1/introduction
- spatie/laravel-activitylog: https://spatie.be/docs/laravel-activitylog/
