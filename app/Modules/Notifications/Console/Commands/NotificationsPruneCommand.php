<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Notifications\Actions\NotificationPruneAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Borra las notificaciones **leídas** más viejas que el plazo.
 *
 * El plazo por defecto es `kore-notifications.prune_days`; `--days=N` lo pisa
 * para una corrida concreta. Lo que nunca se borra es lo no leído: ver el
 * docblock de {@see NotificationPruneAction}.
 *
 * `--dry-run` (`App\Core\Console\Concerns\SupportsDryRun`) hace exactamente el
 * mismo recuento y no escribe nada. La opción la añade el trait: no hace falta
 * declararla en la firma.
 *
 * Sólo está registrado con `NOTIFICATIONS_ENABLED=true` (R10), y por eso su
 * entrada en el scheduler (`routes/console.php`) va detrás del mismo toggle:
 * `Schedule::command()` no falla aunque el comando no exista, así que sin ese
 * `if` el cron intentaría correr un comando inexistente cada noche.
 */
#[Description('Borra las notificaciones leídas más viejas que el plazo configurado')]
#[Signature('notifications:prune {--days= : Días de retención desde que se leyó (por defecto, kore-notifications.prune_days)}')]
final class NotificationsPruneCommand extends Command
{
    use SupportsDryRun;

    public function handle(NotificationPruneAction $prune): int
    {
        $days = $this->days();
        $total = $prune->handle($days, $this->isDryRun());

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se borrarían %d notificación(es) leídas hace más de %d día(s).',
                $total,
                $days,
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'notifications:prune — %d notificación(es) leídas borradas (más de %d día(s)).',
            $total,
            $days,
        ));

        return self::SUCCESS;
    }

    /**
     * Los días efectivos: la opción si viene, si no el config.
     *
     * El suelo lo pone la Action, que es donde se borra: una cifra que decide
     * qué desaparece se acota en el sitio que la aplica, no en el que la lee.
     */
    private function days(): int
    {
        $option = $this->option('days');

        if (is_numeric($option)) {
            return (int) $option;
        }

        $configured = config('kore-notifications.prune_days', 90);

        return is_numeric($configured) ? (int) $configured : 90;
    }
}
