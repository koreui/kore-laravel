<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Se lleva las entregas ya cerradas que pasaron el plazo de retención.
 *
 * Sólo `delivered` y `exhausted`: lo que sigue en juego no se toca nunca, dure
 * lo que dure su reintento. Borrar una entrega pendiente sería perder un evento
 * que todavía tenía que llegar, y el plazo de retención no habla de eso.
 *
 * El corte se mide contra `created_at` y no contra `delivered_at`: una entrega
 * agotada no tiene fecha de entrega, y usar dos relojes distintos según el
 * estado haría que el mismo `--days=30` significara cosas diferentes en la
 * misma tabla.
 *
 * `$dryRun` cuenta exactamente lo mismo y no escribe nada: es lo que corre la
 * primera vez en producción.
 */
final class WebhookDeliveryPruneAction extends Action
{
    public function handle(int $days, bool $dryRun = false): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, $days));

        $query = $this->prunable($cutoff);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function prunable(CarbonImmutable $cutoff): Builder
    {
        return WebhookDelivery::query()
            ->whereIn('status', array_column(DeliveryStatus::closed(), 'value'))
            ->where('created_at', '<', $cutoff);
    }
}
