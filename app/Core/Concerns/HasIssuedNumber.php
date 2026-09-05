<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Contracts\NumberSeries;
use App\Core\Data\IssuedNumberData;
use App\Exceptions\ConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un modelo que lleva folio: número correlativo de una serie, emitido una vez y
 * para siempre.
 *
 * **Opt-in**: hoy ningún modelo del boilerplate lo usa. Para estrenarlo hacen
 * falta el trait y dos columnas:
 *
 * ```php
 * // migración
 * $table->string('number')->nullable()->unique();
 * $table->timestamp('number_issued_at')->nullable();
 *
 * // modelo
 * final class Receipt extends Model
 * {
 *     use HasIssuedNumber;
 * }
 *
 * // al emitir, DENTRO de la transacción que crea el documento
 * DB::transaction(function () use ($receipt, $series): void {
 *     $receipt->save();
 *     $receipt->issueNumber($series, 'receipt');
 * });
 * ```
 *
 * ## El folio se emite una vez
 *
 * `issueNumber()` sobre un documento que ya lo tiene lanza `ConflictException`
 * en vez de pedir otro número. Un folio reemitido deja el anterior impreso en
 * manos de alguien y un hueco en la serie, que es exactamente lo que una
 * auditoría busca. Si de verdad hay que anular, se cancela el documento y se
 * emite **otro**: la serie no retrocede.
 *
 * ## El snapshot del emisor
 *
 * El folio es la mitad del patrón. La otra mitad es que el documento **copia**
 * en su propia fila los datos de quien lo emite —el nombre y el RFC de la
 * organización, tal y como estaban ese día— en vez de referenciarlos:
 *
 * ```php
 * $receipt->issuer_snapshot = resolve(Settings::class)->all();   // o el DTO
 * ```
 *
 * Si mañana la organización cambia de domicilio fiscal, el recibo del mes
 * pasado tiene que seguir diciendo el domicilio que el cliente recibió impreso.
 * Un `belongsTo` a la configuración reescribiría el pasado en silencio cada vez
 * que alguien toca la pantalla de ajustes; eso es lo que hace `notaria_snapshot`
 * en la tabla `recibos` de Notarium, y es la razón de que sea una columna JSON y
 * no una relación. Ver `App\Core\Data\OrganizationData` y
 * `docs/modules/platform.md`.
 *
 * @phpstan-require-extends Model
 */
trait HasIssuedNumber
{
    /** Columna con el folio ya formateado, tal y como se imprime. */
    public const string NUMBER_COLUMN = 'number';

    /** Columna con el instante de la emisión. */
    public const string NUMBER_ISSUED_AT_COLUMN = 'number_issued_at';

    /**
     * Consume el número siguiente de la serie y lo escribe en el modelo.
     *
     * Va todo dentro de una transacción a propósito. Anidada dentro de la que
     * crea el documento se convierte en un savepoint, así que no cambia nada;
     * llamada suelta, garantiza que el contador y la fila avanzan juntos o no
     * avanza ninguno. Lo que no puede pasar es que el contador suba y el
     * documento no se guarde: ése es el hueco en la serie.
     *
     * @throws ConflictException si el documento ya tiene folio
     */
    public function issueNumber(NumberSeries $series, string $seriesName, ?string $scope = null): IssuedNumberData
    {
        if ($this->hasIssuedNumber()) {
            throw new ConflictException(__('Este documento ya tiene folio y un folio no se reemite.'));
        }

        return DB::transaction(function () use ($series, $seriesName, $scope): IssuedNumberData {
            $issued = $series->next($seriesName, $scope);

            /*
             * `setAttribute` y no `fill`: el folio no lo manda nadie desde
             * fuera, así que no tiene por qué estar en el `$fillable` del
             * modelo (R27). Lo pone este trait y sólo este trait.
             */
            $this->setAttribute(self::NUMBER_COLUMN, $issued->formatted);
            $this->setAttribute(self::NUMBER_ISSUED_AT_COLUMN, CarbonImmutable::parse($issued->issuedAt));
            $this->save();

            return $issued;
        });
    }

    /**
     * ¿El documento ya tiene folio?
     *
     * Es lo que separa un borrador de un documento emitido, y por eso lo
     * pregunta también la pantalla: un documento con folio ya no se edita.
     */
    public function hasIssuedNumber(): bool
    {
        return filled($this->getAttribute(self::NUMBER_COLUMN));
    }
}
