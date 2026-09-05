<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Core\Contracts\NumberSeries;
use App\Core\Data\IssuedNumberData;
use App\Modules\Platform\Actions\NumberIssueAction;
use App\Modules\Platform\Models\NumberSequence;

/**
 * Implementación de `App\Core\Contracts\NumberSeries` sobre la tabla
 * `number_sequences`.
 *
 * Es fina a propósito: la emisión —el bloqueo, el reintento, el formato— vive
 * entera en `NumberIssueAction`, porque es un caso de uso y tiene que servir
 * igual desde un comando o un job (R1). Aquí sólo está el `peek()`, que no
 * escribe y por eso no es una Action.
 *
 * Se bindea como singleton en `PlatformModuleServiceProvider::register()`, y
 * siempre: Platform no tiene toggle.
 */
final readonly class DatabaseNumberSeries implements NumberSeries
{
    public function __construct(private NumberIssueAction $issue) {}

    public function next(string $series, ?string $scope = null): IssuedNumberData
    {
        return $this->issue->handle($series, $scope);
    }

    /**
     * El número que devolvería `next()`, sin consumirlo.
     *
     * Sin bloqueo y sin transacción: es una lectura para pintar «el siguiente
     * será el 000123» en un formulario, no una reserva. Entre esto y la
     * emisión puede colarse otro, y está bien que así sea — bloquear aquí
     * detendría a todo el que emita mientras alguien tiene un formulario
     * abierto.
     */
    public function peek(string $series, ?string $scope = null): int
    {
        $definition = SeriesDefinition::fromConfig($series);
        $period = $definition->period(now()->toImmutable());

        $query = NumberSequence::query()
            ->where('series', '=', $series);

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

        $last = $query->value('last_number');

        // Sin contador todavía, el siguiente es el primero de la serie.
        return $last === null ? $definition->start : ((int) $last) + 1;
    }
}
