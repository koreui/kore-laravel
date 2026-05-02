# Observabilidad

**TL;DR**: cuatro herramientas instaladas listas para enchufar en producción: **Sentry** (errores), **Pulse** (performance interno), **spatie/laravel-health** (health checks `/health`), **spatie/laravel-activitylog** (audit log opt-in por modelo).

## Resumen

| Tool                          | Endpoint  | Activación                              | Notas                                  |
|-------------------------------|-----------|-----------------------------------------|----------------------------------------|
| Sentry                        | —         | `SENTRY_LARAVEL_DSN` en `.env`          | sin DSN, el SDK es no-op               |
| Laravel Pulse                 | `/pulse`  | `PULSE_ENABLED=true`                    | dashboard nativo Laravel               |
| spatie/laravel-health         | `/health` | siempre (registra desde `HealthServiceProvider`) | checks DB, cache, disk, schedule, app  |
| spatie/laravel-activitylog    | —         | trait `LogsActivity` por modelo         | tabla `activity_log` lista             |

## 1. Sentry

```env
SENTRY_LARAVEL_DSN=https://xxxxx@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
```

- Si `SENTRY_LARAVEL_DSN` está vacío → SDK queda inactivo, no envía nada.
- Release tracking: el `Dockerfile` recibe `--build-arg GIT_SHA=$(git rev-parse --short HEAD)` y lo expone como `SENTRY_RELEASE` para correlacionar errores con commits.
- Config: `config/sentry.php` (publicado).

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
PULSE_ENABLED=true
```

Dashboard: `/pulse` (proteger con middleware en `App\Providers\PulseServiceProvider` o `Gate::define`).

Recorders activos por defecto: requests, queries lentas, jobs lentos, cache hits, exceptions, slow outgoing requests.

Para producción agregar a `config/pulse.php`:

```php
'middleware' => ['web', 'auth', 'role:admin'],
```

Y un cron para `pulse:check` y `pulse:work`:

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

Endpoints expuestos por el paquete:

- `/health` — vista HTML
- `/health/json` — JSON

**Protegerlos** en producción si los expones públicamente: agrega un token con `HEALTH_OAUTH_TOKEN` y middleware (ver docs Spatie).

Agregar checks: edita `HealthServiceProvider` o crea un check custom (`spatie/laravel-health/v1/available-checks`).

Notificaciones a Slack cuando un check falla:

```php
Health::notifications()->slackWebhookUrl(env('HEALTH_SLACK_WEBHOOK'));
```

Cron requerido para que `ScheduleCheck` reciba heartbeats:

```bash
* * * * * cd /opt/kore-laravel && php artisan schedule:run >> /dev/null 2>&1
```

## 4. spatie/laravel-activitylog

Tabla `activity_log` ya está creada (migración aplicada en Fase 5). Para auditar un modelo:

```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

final class Order extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

Consultar:

```php
$order->activities;            // colección de logs

activity()
    ->causedBy($user)
    ->performedOn($order)
    ->log('cancelled');        // log manual
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

## Recursos

- Sentry Laravel: https://docs.sentry.io/platforms/php/guides/laravel/
- Pulse: https://laravel.com/docs/12.x/pulse
- spatie/laravel-health: https://spatie.be/docs/laravel-health/v1/introduction
- spatie/laravel-activitylog: https://spatie.be/docs/laravel-activitylog/
