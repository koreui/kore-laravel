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
*/

// Health checks. `health:check` almacena los resultados que sirven /health y
// /health/json; `health:schedule-check-heartbeat` es el latido que consume
// ScheduleCheck para saber que el propio scheduler sigue vivo. Sin estos dos
// los checks registrados en HealthServiceProvider nunca corren.
Schedule::command(RunHealthChecksCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(ScheduleCheckHeartbeatCommand::class)
    ->everyMinute()
    ->onOneServer();

// Mantenimiento de colas.
Schedule::command('queue:prune-batches')
    ->daily()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->onOneServer();

// Purga del audit log. La retención es `activitylog.clean_after_days`, que el
// propio paquete registra (365 días por defecto): aquí no se publica
// `config/activitylog.php`. Para cambiarla, `--days=N` o publicar el config con
// `php artisan vendor:publish --tag=activitylog-config`.
Schedule::command('activitylog:clean')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Tokens de Sanctum caducados: sólo tiene sentido con la API encendida.
if ((bool) config('kore-app.api.enabled')) {
    Schedule::command('sanctum:prune-expired --hours=24')
        ->daily()
        ->onOneServer();
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
        ->onOneServer();

    Schedule::command('backup:run')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->onOneServer();

    Schedule::command('backup:monitor')
        ->dailyAt('03:00')
        ->onOneServer();
}

// `model:prune` se deja fuera a propósito: hoy ningún modelo del boilerplate
// usa el trait Prunable / MassPrunable, y el comando aborta si no encuentra
// ninguno. Descoméntalo cuando añadas el primero.
// Schedule::command('model:prune')->daily()->onOneServer();
