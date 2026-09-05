<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Core\Actions\Action;
use App\Core\Data\IssuedNumberData;
use App\Modules\Platform\Models\NumberSequence;
use App\Modules\Platform\Support\SeriesDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Consume el número siguiente de una serie.
 *
 * Es la **única** escritura de `number_sequences`, y es donde vive la garantía
 * de que no hay folios repetidos. Tres candados, y los tres hacen falta:
 *
 * 1. **`DB::transaction`** — el contador y el documento avanzan juntos. Llamada
 *    desde dentro de la transacción que crea el documento (que es como se debe
 *    llamar, ver `App\Core\Concerns\HasIssuedNumber`), ésta se convierte en un
 *    savepoint y no cambia nada.
 * 2. **`lockForUpdate()`** — el segundo que llega espera a que el primero
 *    termine en vez de leer el mismo `last_number`. Es literalmente lo que hace
 *    `ReciboService::emitir()` en Notarium, y la razón de que ese sistema lleve
 *    años sin un folio duplicado.
 * 3. **El reintento** — el bloqueo no sirve cuando la fila **todavía no
 *    existe**: dos peticiones simultáneas sobre una serie estrenada no tienen
 *    qué bloquear, las dos ven que no hay contador y las dos intentan crearlo.
 *    Una gana, la otra choca con el índice único y se reintenta una vez; en el
 *    reintento la fila ya está y el bloqueo hace su trabajo. Una sola vez, no en
 *    bucle: si el segundo intento también choca, lo que falla no es la carrera.
 *
 * El número no se «reserva»: sale de aquí ya gastado. Si el documento no se
 * llega a guardar, la transacción exterior revierte el contador con él.
 */
final class NumberIssueAction extends Action
{
    public function handle(string $series, ?string $scope = null): IssuedNumberData
    {
        $definition = SeriesDefinition::fromConfig($series);
        $issuedAt = CarbonImmutable::now();
        $period = $definition->period($issuedAt);

        $number = $this->advance($definition, $scope, $period);

        return new IssuedNumberData(
            series: $series,
            scope: $scope,
            number: $number,
            formatted: $definition->render($number, $scope, $issuedAt),
            issuedAt: $issuedAt->toIso8601String(),
        );
    }

    /**
     * Sube el contador en uno y devuelve el número resultante.
     */
    private function advance(SeriesDefinition $definition, ?string $scope, ?string $period): int
    {
        try {
            return $this->advanceOnce($definition, $scope, $period);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso creó la fila entre nuestro SELECT y nuestro INSERT.
            // Ahora sí existe, así que el bloqueo de abajo ya sirve.
            return $this->advanceOnce($definition, $scope, $period);
        }
    }

    private function advanceOnce(SeriesDefinition $definition, ?string $scope, ?string $period): int
    {
        return DB::transaction(function () use ($definition, $scope, $period): int {
            $sequence = $this->query($definition->name, $scope, $period)
                ->lockForUpdate()
                ->first();

            if (! $sequence instanceof NumberSequence) {
                /*
                 * `last_number` nace en `start - 1` para que la primera emisión
                 * devuelva `start` sin ningún caso especial. Con `start = 0`
                 * daría -1, que la columna es unsigned y no admite: por eso
                 * `SeriesDefinition` no deja bajar de 0 y aquí se corta igual.
                 */
                $sequence = NumberSequence::query()->create([
                    'series' => $definition->name,
                    'scope' => $scope,
                    'period' => $period,
                    'last_number' => max(0, $definition->start - 1),
                ]);
            }

            $next = $sequence->last_number + 1;

            $sequence->last_number = $next;
            $sequence->save();

            return $next;
        });
    }

    /**
     * El contador de una serie, scope y periodo.
     *
     * Los nulos van con `whereNull` y no con `where(..., null)`: en SQL
     * `columna = NULL` no es falso, es desconocido, y no encuentra nunca la
     * fila del contador global.
     *
     * @return Builder<NumberSequence>
     */
    private function query(string $series, ?string $scope, ?string $period): Builder
    {
        $query = NumberSequence::query()->where('series', '=', $series);

        if ($scope === null) {
            $query->whereNull('scope');
        } else {
            $query->where('scope', '=', $scope);
        }

        if ($period === null) {
            $query->whereNull('period');
        } else {
            $query->where('period', '=', $period);
        }

        return $query;
    }
}
