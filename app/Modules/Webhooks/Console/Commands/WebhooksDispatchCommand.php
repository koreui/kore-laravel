<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Webhooks\Actions\WebhookDeliverAction;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * La red de seguridad del outbox: entrega lo que venció y nadie recogió.
 *
 * En el camino feliz, una entrega sale por el listener en cola nada más
 * confirmarse la transacción que la publicó, y este comando no encuentra nada
 * que hacer. Existe para los dos casos en los que aquello no ocurre:
 *
 *   1. **Los reintentos.** `WebhookDeliverAction` no relanza el job: apunta el
 *      fallo y programa `next_attempt_at`. Quien vuelve a intentarlo es esta
 *      pasada. Que el backoff viva en la base y no en la cola es lo que permite
 *      verlo, cambiarlo y reencolar a mano desde la pantalla.
 *   2. **La cola caída.** Si los workers estuvieron parados, los eventos
 *      quedaron sin escuchar, pero las filas están escritas: al volver, la
 *      siguiente pasada las recoge. Ésa es toda la gracia de un outbox.
 *
 * El scheduler lo corre cada minuto con `WEBHOOKS_ENABLED=true`, sin
 * solapamiento. El tope de `kore-webhooks.dispatch_batch` evita que una caída
 * larga del receptor se convierta en una ráfaga de diez mil peticiones cuando
 * vuelve.
 */
#[Description('Entrega los webhooks pendientes cuyo reintento ya venció')]
#[Signature('webhooks:dispatch {--limit= : Cuántas entregas como máximo (por defecto, kore-webhooks.dispatch_batch)}')]
final class WebhooksDispatchCommand extends Command
{
    use SupportsDryRun;

    public function handle(WebhookDeliverAction $deliver): int
    {
        $now = CarbonImmutable::now();
        $limit = $this->limit();

        // Se recogen las filas ANTES de tocar nada, para que el ensayo y la
        // corrida de verdad miren exactamente el mismo estado.
        $due = WebhookDelivery::query()
            ->with('endpoint')
            ->due($now)
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se intentarían %d entrega(s) vencida(s) a fecha de %s (tope %d).',
                $due->count(),
                $now->toDateTimeString(),
                $limit,
            ));

            return self::SUCCESS;
        }

        foreach ($due as $delivery) {
            $deliver->handle($delivery);
        }

        $this->components->info(sprintf(
            'webhooks:dispatch — %d entrega(s) intentada(s).',
            $due->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Tope de la pasada, con suelo en 1: un `--limit=0` no es una configuración,
     * es un comando que no hace nada y no lo dice.
     */
    private function limit(): int
    {
        $option = $this->option('limit');

        if (is_numeric($option)) {
            return max(1, (int) $option);
        }

        return max(1, (int) config('kore-webhooks.dispatch_batch', 100));
    }
}
