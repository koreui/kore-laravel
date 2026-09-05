<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué quiere recibir cada persona y por dónde.
 *
 * Una fila por (usuario × categoría), y `unique` sobre las dos columnas: la
 * pantalla de preferencias hace `updateOrCreate`, así que sin la unicidad dos
 * pestañas abiertas dejarían dos filas contradictorias para el mismo par.
 *
 * **La ausencia de fila NO significa «apagado»**: significa «lo que traiga el
 * default de la categoría» (`kore-notifications.categories`). Es lo que permite
 * añadir una categoría nueva sin sembrar una fila para toda la plantilla, y lo
 * que hace que un usuario recién creado reciba lo que tiene que recibir sin
 * pasar por ningún seeder.
 *
 * `category` es un `string` y no un enum en la base, por lo mismo que en el DTO:
 * un derivado añade categorías propias en su config y un `enum` de columna le
 * obligaría a una migración por cada una.
 *
 * Se migra siempre, con el toggle encendido o apagado; ver el docblock de la
 * migración de `notifications`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');

            // `in_app` es el canal base: apagado, el aviso ni siquiera se
            // guarda para esa persona.
            $table->boolean('in_app')->default(true);
            $table->boolean('mail')->default(true);
            $table->boolean('push')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
