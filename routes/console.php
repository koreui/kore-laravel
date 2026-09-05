<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| Un solo cron en el servidor dispara todo esto:
|
|   * * * * * cd /opt/kore-laravel && php artisan schedule:run >> /dev/null 2>&1
|
| (En Docker lo hace el servicio `scheduler` de docker-compose.prod.yml.)
|
| Toda tarea lleva `->sentryMonitor()`, la macro de sentry-laravel que abre un
| check-in al empezar y lo cierra al terminar. Es lo que convierte «el cron dejó
| de correr» en una alerta: sin monitor, un scheduler parado no produce ningún
| error —no hay excepción que reportar— y se descubre el día que hace falta el
| backup. Sin `SENTRY_LARAVEL_DSN` la macro sigue existiendo y el check-in se
| va por un `return` temprano, así que en local y en los tests no cuesta nada.
|
*/

// Health checks. `health:check` almacena los resultados que sirven /health y
// /health/json; `health:schedule-check-heartbeat` es el latido que consume
// ScheduleCheck para saber que el propio scheduler sigue vivo. Sin estos dos
// los checks registrados en HealthServiceProvider nunca corren.
Schedule::command(RunHealthChecksCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

Schedule::command(ScheduleCheckHeartbeatCommand::class)
    ->everyMinute()
    ->onOneServer()
    ->sentryMonitor();

// Mantenimiento de colas.
Schedule::command('queue:prune-batches')
    ->daily()
    ->onOneServer()
    ->sentryMonitor();

Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->onOneServer()
    ->sentryMonitor();

// Purga del audit log. La retención es `activitylog.clean_after_days`, que el
// propio paquete registra (365 días por defecto): aquí no se publica
// `config/activitylog.php`. Para cambiarla, `--days=N` o publicar el config con
// `php artisan vendor:publish --tag=activitylog-config`.
Schedule::command('activitylog:clean')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor();

// Tokens de Sanctum caducados: sólo tiene sentido con la API encendida.
if ((bool) config('kore-app.api.enabled')) {
    Schedule::command('sanctum:prune-expired --hours=24')
        ->daily()
        ->onOneServer()
        ->sentryMonitor();
}

// Backups (spatie/laravel-backup): sólo con BACKUP_ENABLED=true, porque con el
// toggle apagado los comandos ni siquiera están registrados. El orden importa:
// primero se limpia lo viejo (para que quepa el nuevo), después se hace el
// backup del día y por último se comprueba que existe y no es demasiado
// antiguo. `backup:run` va sin solapamiento porque un dump grande puede
// pasarse de la hora.
if ((bool) config('kore-app.backup.enabled')) {
    Schedule::command('backup:clean')
        ->dailyAt('01:00')
        ->onOneServer()
        ->sentryMonitor();

    Schedule::command('backup:run')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->onOneServer()
        ->sentryMonitor();

    Schedule::command('backup:monitor')
        ->dailyAt('03:00')
        ->onOneServer()
        ->sentryMonitor();
}

// Inventario de dispositivos (módulo Devices): sólo con DEVICES_ENABLED=true,
// porque con el toggle apagado el comando `devices:cleanup` ni siquiera está
// registrado — y `Schedule::command()` no falla aunque el comando no exista, así
// que sin este `if` el scheduler intentaría correr un comando inexistente cada
// noche. Es la misma pieza que aprendió el toggle de backup.
//
// A las 04:00, detrás del backup de la noche: si la purga se lleva algo que no
// debía, el zip de las 02:00 todavía lo tiene.
if ((bool) config('kore-app.devices.enabled')) {
    Schedule::command('devices:cleanup')
        ->dailyAt('04:00')
        ->withoutOverlapping()
        ->onOneServer()
        ->sentryMonitor();
}

// Purga de versiones archivadas (módulo Files): sólo con FILES_ENABLED=true,
// porque con el toggle apagado `files:cleanup` ni siquiera está registrado —y
// `Schedule::command()` no falla aunque el comando no exista, así que sin este
// `if` el scheduler intentaría correr un comando inexistente cada noche.
//
// A las 04:30, detrás del backup de las 02:00 y de `devices:cleanup`: si la
// purga se lleva un fichero que hacía falta, el zip de la noche todavía lo
// tiene. Los 30 días son el plazo por defecto y se escriben AQUÍ, no en el
// config: borrar es destructivo y la cifra tiene que verse en la línea que la
// aplica.
if ((bool) config('kore-app.files.enabled')) {
    Schedule::command('files:cleanup --days=30')
        ->dailyAt('04:30')
        ->withoutOverlapping()
        ->onOneServer()
        ->sentryMonitor();
}

// Purga de códigos de invitación caducados (módulo Auth): sólo con
// AUTH_INVITATIONS=true, porque con el toggle apagado `invitations:prune` ni
// siquiera está registrado —y `Schedule::command()` no falla aunque el comando
// no exista, así que sin este `if` el scheduler intentaría correr un comando
// inexistente cada noche. Es la misma pieza que aprendieron los toggles de
// backup, devices y files.
//
// A las 04:45, detrás del backup de las 02:00: si la purga se lleva un código
// que hacía falta consultar, el zip de la noche todavía lo tiene. Los 90 días
// se escriben AQUÍ y no en un config: borrar es destructivo y la cifra tiene
// que verse en la línea que la aplica.
if ((bool) config('kore-app.auth.invitations')) {
    Schedule::command('invitations:prune --days=90')
        ->dailyAt('04:45')
        ->withoutOverlapping()
        ->onOneServer()
        ->sentryMonitor();
}

// `model:prune` se deja fuera a propósito: hoy ningún modelo del boilerplate
// usa el trait Prunable / MassPrunable, y el comando aborta si no encuentra
// ninguno. Descoméntalo cuando añadas el primero.
// Schedule::command('model:prune')->daily()->onOneServer()->sentryMonitor();
