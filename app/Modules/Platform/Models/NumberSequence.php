<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * El contador de una serie de folio.
 *
 * Una fila por combinación de serie, scope y periodo, y la clave única de la
 * tabla es exactamente esa terna: es lo que hace que dos peticiones que piden
 * folio de la misma serie compitan por la **misma** fila y no por dos.
 *
 * `last_number` es el último número **entregado**, no el siguiente: el folio 7
 * emitido deja la fila en 7. Así una fila recién creada vale `start - 1` y la
 * primera emisión devuelve `start` sin ningún caso especial.
 *
 * Nadie escribe aquí fuera de `App\Modules\Platform\Actions\NumberIssueAction`,
 * que es quien tiene el `lockForUpdate()`. Un `increment()` suelto desde otro
 * sitio se saltaría el bloqueo y volvería a abrir la puerta al folio duplicado.
 *
 * @property string $series
 * @property string|null $scope
 * @property string|null $period
 * @property int $last_number
 */
#[Fillable(['series', 'scope', 'period', 'last_number'])]
final class NumberSequence extends Model
{
    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
