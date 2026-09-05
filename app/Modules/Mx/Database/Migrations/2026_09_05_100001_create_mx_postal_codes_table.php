<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de SEPOMEX: una fila por asentamiento (colonia) de cada código
 * postal.
 *
 * La tabla se crea siempre, con el toggle encendido o apagado, y **nace vacía**:
 * los datos son de un tercero, pesan catorce megas y tienen su propia licencia,
 * así que no viajan en el repositorio. Los trae `php artisan mx:sepomex:import`
 * desde el CSV oficial (ver `docs/modules/mx.md`).
 *
 * Un código postal tiene varias colonias —de ahí que la clave no sea
 * `postal_code`— y el índice por esa columna es el que sostiene la única
 * consulta del módulo: `WHERE postal_code = ?`.
 *
 * La única compuesta es la de la importación: `(postal_code, settlement,
 * settlement_type)` es lo que hace que un `upsert` repetido actualice en vez de
 * duplicar, y por eso `settlement_type` NO es nullable —en MySQL y en SQLite dos
 * filas con NULL en una columna del índice único no chocan entre sí, así que un
 * tipo nulo reabriría la puerta a los duplicados que el índice viene a cerrar—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mx_postal_codes', function (Blueprint $table): void {
            $table->id();

            // Cinco dígitos, con los ceros de la izquierda: '01000' es de la
            // Ciudad de México y '1000' no es un código postal.
            $table->string('postal_code', 5)->index();

            // La colonia y su tipo ('Colonia', 'Fraccionamiento', 'Pueblo'...),
            // tal y como los publica SEPOMEX.
            $table->string('settlement');
            $table->string('settlement_type');

            $table->string('municipality');

            // La ciudad sí falta a menudo: el CSV la deja vacía en los
            // municipios que no forman parte de una zona urbana con nombre.
            $table->string('city')->nullable();

            $table->string('state_code', 2);

            // Apunta a `mx_states.code` y no a su id: la clave del SAT es la
            // identidad natural del catálogo y es la que viene en el CSV, así
            // que la FK ahorra una traducción por fila en una importación de
            // 145 000.
            $table->foreign('state_code')
                ->references('code')
                ->on('mx_states')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unique(
                ['postal_code', 'settlement', 'settlement_type'],
                'mx_postal_codes_settlement_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mx_postal_codes');
    }
};
