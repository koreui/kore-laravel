<?php

declare(strict_types=1);

namespace App\Modules\Devices\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mantenimiento del inventario de dispositivos.
 *
 * Tres pasos, en este orden y por una razón:
 *
 * 1. **Revocar los abandonados.** Un dispositivo que lleva
 *    `devices.stale_after_days` sin aparecer es un teléfono vendido, perdido o
 *    reinstalado; su token sigue siendo válido hasta que caduque, y ésa es la
 *    credencial que nadie va a echar de menos si se la roban.
 * 2. **Borrar sus tokens** —los de los recién revocados, los de los que ya
 *    estaban revocados y los caducados que aún cuelgan de alguna fila—. Revocar
 *    sin borrar el token dejaría el dispositivo marcado y funcionando, que es
 *    el peor de los dos mundos.
 * 3. **Purgar los revocados antiguos.** Pasado `devices.prune_after_days`, la
 *    fila deja de ser auditoría y pasa a ser un dato personal guardado sin
 *    motivo.
 *
 * Los dos relojes son distintos a propósito: primero se revoca por silencio y
 * **después** empieza a correr la retención. Por eso un dispositivo revocado
 * hoy no se borra hoy, ni en la misma corrida.
 *
 * `--dry-run` (`App\Core\Console\Concerns\SupportsDryRun`) cuenta exactamente lo
 * mismo y no escribe nada: es lo que se corre la primera vez en producción.
 */
#[Description('Revoca dispositivos abandonados y borra los revocados hace tiempo, con sus tokens de Sanctum')]
#[Signature('devices:cleanup')]
final class DevicesCleanupCommand extends Command
{
    use SupportsDryRun;

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $staleCutoff = $now->subDays($this->days('stale_after_days', 180));
        $pruneCutoff = $now->subDays($this->days('prune_after_days', 90));

        // Se cuenta y se recogen los ids ANTES de tocar nada: así el ensayo y
        // la corrida de verdad miran exactamente el mismo estado.
        $staleCount = $this->staleDevices($staleCutoff)->count();
        $tokenIds = $this->deletableTokenIds($staleCutoff, $now);
        $prunableCount = $this->prunableDevices($pruneCutoff)->count();

        if ($this->isDryRun()) {
            $this->dryRunNotice(sprintf(
                'se revocarían %d dispositivo(s) sin actividad desde %s, se borrarían %d token(s) de Sanctum y %d dispositivo(s) revocados antes de %s.',
                $staleCount,
                $staleCutoff->toDateString(),
                count($tokenIds),
                $prunableCount,
                $pruneCutoff->toDateString(),
            ));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($staleCutoff, $pruneCutoff, $tokenIds, $now): void {
            $this->staleDevices($staleCutoff)->update(['revoked_at' => $now]);

            if ($tokenIds !== []) {
                PersonalAccessToken::query()->whereKey($tokenIds)->delete();
            }

            $this->prunableDevices($pruneCutoff)->delete();
        });

        $this->components->info(sprintf(
            'devices:cleanup — %d revocado(s), %d token(s) borrado(s), %d dispositivo(s) purgado(s).',
            $staleCount,
            count($tokenIds),
            $prunableCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Días de una clave de `config/devices.php`, con suelo en 1.
     *
     * Un `0` convertiría el comando en «borra todo lo de hoy», que no es una
     * configuración: es un accidente.
     */
    private function days(string $key, int $default): int
    {
        $value = config("devices.{$key}", $default);

        return max(1, is_numeric($value) ? (int) $value : $default);
    }

    /**
     * Dispositivos vivos que llevan demasiado tiempo callados.
     *
     * El `orWhere` sobre `created_at` cubre al que se registró y no volvió
     * nunca: sin él, un `last_seen_at` nulo lo dejaría vivo para siempre.
     *
     * @return Builder<Device>
     */
    private function staleDevices(CarbonImmutable $cutoff): Builder
    {
        return Device::query()
            ->active()
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('last_seen_at', '<', $cutoff)
                    ->orWhere(function (Builder $inner) use ($cutoff): void {
                        $inner->whereNull('last_seen_at')->where('created_at', '<', $cutoff);
                    });
            });
    }

    /**
     * Dispositivos revocados hace más del plazo de retención.
     *
     * @return Builder<Device>
     */
    private function prunableDevices(CarbonImmutable $cutoff): Builder
    {
        return Device::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff);
    }

    /**
     * Tokens de Sanctum que ya no debería tener nadie: los de un dispositivo
     * revocado (o a punto de serlo) y los caducados que siguen colgando de una
     * fila.
     *
     * Los que no cuelgan de ningún dispositivo no son asunto de este comando:
     * de ésos se ocupa `sanctum:prune-expired`, que el scheduler ya corre a
     * diario.
     *
     * @return list<int>
     */
    private function deletableTokenIds(CarbonImmutable $staleCutoff, CarbonImmutable $now): array
    {
        $ids = array_merge(
            $this->tokenIdsOf(Device::query()->whereNotNull('revoked_at')),
            $this->tokenIdsOf($this->staleDevices($staleCutoff)),
            $this->expiredTokenIds($now),
        );

        return array_values(array_unique($ids));
    }

    /**
     * De los tokens que cuelgan de un dispositivo, los que ya caducaron.
     *
     * Se parte de los ids del inventario en vez de preguntar por todos los
     * `personal_access_tokens` caducados: los que no son de un dispositivo son
     * cosa de `sanctum:prune-expired`, y borrarlos aquí sería este comando
     * haciendo el trabajo de otro sin decirlo.
     *
     * @return list<int>
     */
    private function expiredTokenIds(CarbonImmutable $now): array
    {
        $candidates = $this->tokenIdsOf(Device::query());

        if ($candidates === []) {
            return [];
        }

        return array_values(array_map(
            intval(...),
            PersonalAccessToken::query()
                ->whereKey($candidates)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->pluck('id')
                ->all(),
        ));
    }

    /**
     * @param Builder<Device> $query
     * @return list<int>
     */
    private function tokenIdsOf(Builder $query): array
    {
        return array_values(array_map(
            intval(...),
            $query->whereNotNull('access_token_id')->pluck('access_token_id')->all(),
        ));
    }
}
