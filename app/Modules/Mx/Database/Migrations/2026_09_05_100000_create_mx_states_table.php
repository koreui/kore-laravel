<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las 32 entidades federativas, con su clave SAT/INEGI.
 *
 * La carga **siempre** `MxModuleServiceProvider`, también con `MX_ENABLED=false`:
 * un toggle apaga rutas y comportamiento, nunca el esquema. Es el mismo criterio
 * que `devices` y `media` (ver `docs/architecture/toggles.md`).
 *
 * `code` es una cadena de dos caracteres y no un entero a propósito: la clave
 * oficial se escribe con el cero delante (`01` Aguascalientes, `09` Ciudad de
 * México) y va impresa así en facturas y escrituras. Guardarla como `int` obliga
 * a rellenarla con `str_pad` en cada sitio que la imprime, y basta olvidarse una
 * vez para publicar `9` donde el SAT espera `09`.
 *
 * El nombre de la tabla lleva prefijo `mx_` porque el catálogo es de un país: un
 * derivado que mañana añada el suyo no tiene que renombrar nada.
 *
 * Va antes que `mx_postal_codes` en el orden de migración porque esa tabla
 * apunta aquí con una clave foránea, y Laravel las ordena por nombre de archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mx_states', function (Blueprint $table): void {
            $table->id();

            // Clave SAT/INEGI: '01'..'32'. Única porque es a lo que apunta la
            // FK de mx_postal_codes.
            $table->string('code', 2)->unique();

            $table->string('name');

            // Abreviatura INEGI: 'AGS', 'CDMX', 'QROO'... Cinco y no cuatro
            // porque Tamaulipas es 'TAMPS' y es la más larga de las 32.
            $table->string('abbreviation', 5);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mx_states');
    }
};
