<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores de las series de folio (módulo Platform).
 *
 * Una fila por serie, scope y periodo. La clave única de esa terna no es un
 * detalle de higiene: es la mitad de la garantía de que no hay folios
 * duplicados. La otra mitad es el `lockForUpdate()` de
 * `App\Modules\Platform\Actions\NumberIssueAction`, y las dos hacen falta —el
 * bloqueo serializa a los que llegan a la vez, y el índice único impide que dos
 * que llegan a la vez creen **dos filas** para el mismo contador cuando todavía
 * no existía ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();

            // El nombre con el que se pide el folio: `next('receipt')`.
            $table->string('series', 100);

            /*
             * Separa contadores dentro de la misma serie (una sucursal, una
             * caja, un tenant) sin declarar una serie nueva. Nullable = el
             * contador global de la serie.
             */
            $table->string('scope', 100)->nullable();

            /*
             * El periodo del reinicio, ya normalizado a texto: `2026` con
             * `reset => yearly`, y NULL con `reset => never`. Se guarda
             * resuelto y no como fecha para que la clave única lo pueda
             * comparar tal cual y para que cambiar la política de reinicio de
             * una serie no reinterprete las filas viejas.
             */
            $table->string('period', 20)->nullable();

            /*
             * El último número ENTREGADO, no el siguiente. Una fila recién
             * creada vale `start - 1`, así que la primera emisión devuelve
             * `start` sin ningún caso especial.
             */
            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();

            /*
             * En MySQL y en SQLite dos filas con NULL en la misma columna NO
             * chocan en un índice único, así que esta clave no protege por sí
             * sola al contador global (scope y period nulos). Lo que sí lo
             * protege es el `lockForUpdate()` de la Action, que serializa a los
             * concurrentes, más el reintento único ante una violación de
             * unicidad. El índice es la red del caso con scope o con periodo,
             * que es el que más filas produce.
             */
            $table->unique(['series', 'scope', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
