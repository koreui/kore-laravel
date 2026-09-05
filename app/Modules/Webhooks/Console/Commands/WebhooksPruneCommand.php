<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Webhooks\Actions\WebhookDeliveryPruneAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Se lleva las entregas ya cerradas que pasaron el plazo de retención.
 *
 * El outbox es una tabla que sólo crece: una instalación con tres endpoints y
 * cien eventos al día escribe cien mil filas al año, y ninguna de las
 * entregadas hace tres meses le sirve ya a nadie. Lo que sigue en juego no se
 * toca (ver `WebhookDeliveryPruneAction`).
 *
 * Los 30 días por defecto se escriben en la línea del scheduler y no en el
 * config: borrar es destructivo y la cifra tiene que verse donde se aplica.
 */
#[Description('Borra las entregas de webhook ya cerradas (entregadas o agotadas) más antiguas que --days')]
#[Signature('webhooks:prune {--days=30 : Días de retención de las entregas cerradas}')]
final class WebhooksPruneCommand extends Command
{
    use SupportsDryRun;

    public function handle(WebhookDeliveryPruneAction $prune): int
    {
        $days = max(1, (int) $this->option('days'));

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se borrarían %d entrega(s) cerrada(s) con más de %d día(s).',
                $prune->handle($days, dryRun: true),
                $days,
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'webhooks:prune — %d entrega(s) borrada(s) con más de %d día(s).',
            $prune->handle($days),
            $days,
        ));

        return self::SUCCESS;
    }
}
