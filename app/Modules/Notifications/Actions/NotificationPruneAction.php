<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Core\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Poda de la bandeja: se lleva las notificaciones **leídas** más viejas que el
 * plazo.
 *
 * Las no leídas no se borran nunca por edad, y no es una cautela decorativa: si
 * nadie las vio, borrarlas es perder el aviso. Una bandeja crece rápido —cada
 * login por API, cada asignación— y sin poda acaba siendo una tabla que nadie
 * mira pero que todas las pantallas consultan.
 *
 * El plazo se cuenta desde `read_at` y no desde `created_at`: lo que caduca es
 * el aviso ya atendido, no el aviso viejo. Una notificación de hace un año que
 * se leyó ayer sigue siendo reciente para esto.
 *
 * `$dryRun` cuenta exactamente lo mismo y no escribe nada: es lo que corre
 * `notifications:prune --dry-run` la primera vez en producción.
 */
final class NotificationPruneAction extends Action
{
    /** @return int cuántas se borraron (o se borrarían, en un ensayo) */
    public function handle(int $days, bool $dryRun = false): int
    {
        // Suelo en 1: un `0` convertiría la poda en «borra todo lo leído hoy»,
        // que no es una configuración sino un accidente.
        $cutoff = CarbonImmutable::now()->subDays(max(1, $days));

        $query = DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoff);

        $total = $query->count();

        if ($dryRun) {
            return $total;
        }

        $query->delete();

        return $total;
    }
}
