# Observabilidad

**TL;DR**: cinco herramientas instaladas listas para enchufar en producción: **Sentry** (errores), **Pulse** (performance interno), **spatie/laravel-health** (health checks `/health`), **spatie/laravel-activitylog** (audit log opt-in por modelo) y **spatie/laravel-backup** (copias cifradas y vigiladas, toggle `BACKUP_ENABLED`).

## Resumen

| Tool                       | Endpoint                | Activación                                      | Acceso                                        |
|----------------------------|-------------------------|-------------------------------------------------|-----------------------------------------------|
| Sentry                     | —                       | `SENTRY_LARAVEL_DSN` en `.env`                   | —                                             |
| Laravel Pulse              | `/pulse`                | `PULSE_ENABLED=true` (default `false`)           | `auth` + gate `viewPulse` → sólo superadmin    |
| spatie/laravel-health      | `/health`, `/health/json` | siempre (`HealthServiceProvider`)              | HTML: `auth` + gate `viewHealth`; JSON: token   |
| spatie/laravel-activitylog | —                       | trait `LogsActivity` por modelo                  | ya activo en `User` y `Role`                   |
| spatie/laravel-backup      | `/health` (`BackupsCheck`) | `BACKUP_ENABLED=true` (default `false`)     | avisos por correo a `BACKUP_NOTIFICATION_MAIL`  |

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

## 5. Backups vigilados (spatie/laravel-backup)

Los backups tienen **dos vigilantes**, y no son redundantes: uno empuja, el otro
espera a que le pregunten.

**`backup:monitor`** (03:00, `routes/console.php`) recorre
`config('backup.monitor_backups')` y comprueba, para cada disco de destino, que
el último backup no supere `BACKUP_MAX_AGE_DAYS` días (`MaximumAgeInDays`,
1 por defecto) ni el conjunto los 5000 MB (`MaximumStorageInMegabytes`). Si algo
falla dispara `UnhealthyBackupWasFound` y sale un correo a
`BACKUP_NOTIFICATION_MAIL` (o a `MAIL_FROM_ADDRESS`). Por el mismo canal salen
los avisos de `backup:run` y `backup:clean`, en éxito y en fallo — el catálogo
completo está en `config/backup.php` → `notifications.notifications`. Para dejar
sólo los fallos, borra de ahí las tres notificaciones `...WasSuccessful`.

La lista de discos que vigila el monitor **es literalmente la misma variable
PHP** que la de destino: las dos salen de `$disks` en `config/backup.php`. Es la
única forma de que no se desincronicen —el caso clásico es mandar los zips a S3
y dejar el monitor mirando `local`, donde ya no llega nada—, y
`tests/Feature/BackupTest.php` lo comprueba comparando las dos claves.

**`BackupsCheck`** (spatie/laravel-health, registrado por
`app/Providers/BackupServiceProvider.php` cuando `BACKUP_ENABLED=true`) pone lo
mismo en `/health` y `/health/json`, que es donde mira un monitor externo. Abre
el **primer** disco de `BACKUP_DISKS`, lista la carpeta `BACKUP_NAME` y falla si
no hay ningún backup o si el más reciente tiene más de un día. Con el toggle
apagado el check no se registra y `/health` no menciona backups.

> El check se registra desde `BackupServiceProvider` y no desde
> `HealthServiceProvider` porque `Health::checks()` **acumula** (hace
> `array_merge`, no sustituye): así el check vive pegado al toggle que lo
> enciende y no hay que leer `kore-app.backup.enabled` desde dos sitios.

Comprobación manual:

```bash
php artisan backup:list      # qué hay, dónde, de cuándo y cuánto pesa
php artisan backup:monitor   # el veredicto del monitor, sin esperar a las 03:00
curl -s -H "X-Secret-Token: $HEALTH_SECRET_TOKEN" https://tu-dominio.com/health/json | jq '.checkResults[] | select(.name == "Backups")'
```

El detalle de configuración, retención y la receta de restore están en
[`deployment.md`](deployment.md#backups-spatielaravel-backup).

## 6. Scheduler

`routes/console.php` concentra todas las tareas programadas:

| Comando                                | Frecuencia | Por qué                                        |
|----------------------------------------|------------|------------------------------------------------|
| `health:check`                         | cada minuto| genera los resultados de `/health`             |
| `health:schedule-check-heartbeat`      | cada minuto| latido que consume `ScheduleCheck`             |
| `queue:prune-batches`                  | diario     | limpia batches viejos                          |
| `queue:prune-failed --hours=168`       | diario     | limpia jobs fallidos de más de 7 días          |
| `activitylog:clean`                    | diario     | purga el audit log                             |
| `sanctum:prune-expired --hours=24`     | diario     | sólo si `API_ENABLED=true`                     |
| `backup:clean`                         | 01:00      | sólo si `BACKUP_ENABLED=true`: retención        |
| `backup:run`                           | 02:00      | sólo si `BACKUP_ENABLED=true`: dump + zip cifrado |
| `backup:monitor`                       | 03:00      | sólo si `BACKUP_ENABLED=true`: edad y tamaño    |

`model:prune` está comentado a propósito: hoy ningún modelo usa `Prunable`.

Verificar en cualquier momento:

```bash
php artisan schedule:list
```

## Logs estructurados

`config/logging.php` ya trae el canal `stderr` (driver `monolog`,
`StreamHandler` sobre `php://stderr`) y acepta un formatter por variable de
entorno. En producción no hace falta editar el config: basta con el `.env`:

```env
LOG_CHANNEL=stack
LOG_STACK=stderr            # o `stderr,sentry`
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
LOG_LEVEL=warning
```

Docker recoge stderr y lo rota (`x-logging` de `docker-compose.prod.yml`, 10 MB
× 5 por contenedor). `tests/Feature/LoggingTest.php` comprueba que esas
variables construyen el logger esperado. Detalle en
[`deployment.md`](deployment.md#logs).

## Tests

La observabilidad tiene cinco suites propias en `tests/Feature/`, 42 tests en
total (`./vendor/bin/pest tests/Feature/HealthTest.php tests/Feature/PulseAccessTest.php tests/Feature/SentryIntegrationTest.php tests/Feature/BackupTest.php tests/Feature/LoggingTest.php`):

| Archivo | Qué cubre | Tests |
|---------|-----------|-------|
| `HealthTest` | los dos endpoints, el gate `viewHealth`, el token de `/health/json` y los checks registrados | 12 |
| `PulseAccessTest` | el gate `viewPulse` (sólo superadmin) y que las rutas existan con el toggle apagado | 5 |
| `SentryIntegrationTest` | que `Integration::handles()` esté enganchado y el canal de log `sentry` declarado | 4 |
| `BackupTest` | el toggle `BACKUP_ENABLED` en las dos posiciones, las tres tareas programadas, que el monitor y el `BackupsCheck` vigilan el destino real y que el zip sale cifrado | 16 |
| `LoggingTest` | que la receta de `.env` de producción construye el canal `stderr` con `JsonFormatter` y el nivel esperado | 5 |

`PulseAccessTest` renderiza el dashboard de Pulse, que arrastra su bundle JS: por
eso `phpunit.xml` fija `memory_limit=512M`. Con el 128M de algunas instalaciones
de PHP la suite se cae por memoria, no por el test.

## Recursos

- Sentry Laravel: https://docs.sentry.io/platforms/php/guides/laravel/
- Pulse: https://laravel.com/docs/12.x/pulse
- spatie/laravel-health: https://spatie.be/docs/laravel-health/v1/introduction
- spatie/laravel-activitylog: https://spatie.be/docs/laravel-activitylog/
- spatie/laravel-backup: https://spatie.be/docs/laravel-backup/v10/introduction
