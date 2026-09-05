<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de la instalación (módulo Platform).
 *
 * Platform no tiene toggle, así que aquí no hay nada que decidir: la tabla se
 * migra siempre, como el resto del esquema.
 *
 * El timestamp del nombre va detrás del de `users` (`0001_01_01_000000`) porque
 * `changed_by` es una clave foránea a esa tabla, y Laravel ordena todas las
 * migraciones pendientes —las de `database/` y las de los módulos— por nombre de
 * archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            /*
             * La clave con puntos (`organization.name`). 191 caracteres es el
             * máximo indexable con utf8mb4 en un MySQL viejo, y `unique` es lo
             * que convierte la tabla en un mapa: un ajuste, una fila.
             */
            $table->string('key', 191)->unique();

            /*
             * JSON y no `text`: un ajuste puede ser booleano o entero, y
             * guardarlo todo como cadena obligaría a cada lector a saber qué
             * casteo le toca. Nullable porque «guardado y vacío» es un valor
             * legítimo, distinto de «sin fila» — que significa «vale lo que
             * dice config/kore-settings.php».
             */
            $table->json('value')->nullable();

            /*
             * Quién lo cambió la última vez. `nullOnDelete` y no `cascade`:
             * borrar al administrador que configuró la organización no puede
             * borrar el nombre de la organización. Lo que se pierde es el
             * rastro, no el ajuste.
             */
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
