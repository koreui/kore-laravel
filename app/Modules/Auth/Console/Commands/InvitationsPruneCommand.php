<?php

declare(strict_types=1);

namespace App\Modules\Auth\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Borra los códigos que llevan tiempo caducados.
 *
 * **Sólo los caducados, y sólo los caducados hace tiempo.** Un código sin
 * `expires_at` no se toca aunque esté agotado: agotado no es lo mismo que
 * cerrado, porque subirle el cupo vuelve a abrirlo, y borrar la fila perdería
 * el rastro de cuánta gente entró por él. El plazo existe por la misma razón:
 * el día siguiente a la caducidad es justo cuando alguien pregunta «¿qué pasó
 * con el código que repartimos?».
 *
 * Quien ya se registró con un código conserva su cuenta: aquí no hay cascada
 * hacia `users`.
 *
 * `--dry-run` (`App\Core\Console\Concerns\SupportsDryRun`) cuenta lo mismo y no
 * escribe nada: es lo que se corre la primera vez en producción.
 */
#[Description('Borra los códigos de invitación caducados hace más de N días')]
#[Signature('invitations:prune {--days=90 : Días desde la caducidad antes de borrar}')]
final class InvitationsPruneCommand extends Command
{
    use SupportsDryRun;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $count = $this->prunable($cutoff)->count();

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se borrarían %d código(s) de invitación caducados antes de %s.',
                $count,
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $this->prunable($cutoff)->delete();

        $this->components->info(sprintf('invitations:prune — %d código(s) borrado(s).', $count));

        return self::SUCCESS;
    }

    /** @return Builder<InvitationCode> */
    private function prunable(CarbonImmutable $cutoff): Builder
    {
        return InvitationCode::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff);
    }
}
