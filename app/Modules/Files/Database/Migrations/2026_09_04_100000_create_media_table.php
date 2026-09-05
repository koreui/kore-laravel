<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `media` de spatie/laravel-medialibrary.
 *
 * Publicada con `php artisan vendor:publish --tag=medialibrary-migrations` y
 * movida aquí: la migración es del módulo que la usa, como la de `devices`. Lo
 * único añadido al archivo del paquete es el `down()` que exige R29 —el
 * paquete no lo trae— y el índice de abajo.
 *
 * **Se carga siempre, también con `FILES_ENABLED=false`.** No es una válvula:
 * es la regla. Un toggle apaga rutas y comportamiento, nunca la forma de la
 * base (`docs/architecture/toggles.md`). Si la migración fuera condicional, dos
 * instalaciones del mismo commit tendrían esquemas distintos según el `.env`
 * del día en que se migró, y encender el toggle en producción exigiría una
 * migración a mano justo cuando ya hay tráfico. El precedente es
 * `AUTH_PASSKEYS=false`, que apaga la pantalla y migra igual la tabla
 * `passkeys`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();

            /*
             * El único acceso que hace el módulo es «dame las versiones del
             * slot X del modelo Y», y `morphs()` ya indexa
             * (`model_type`, `model_id`). Falta la colección, que es lo que
             * acota de verdad cuando un modelo tiene varias: sin este índice,
             * pedir el avatar de un usuario recorre también sus contratos.
             *
             * La huella del slot vive dentro de `custom_properties`, que es
             * JSON y no se indexa igual en SQLite, MySQL y Postgres. Se deja
             * fuera a propósito: el filtro por colección ya deja un conjunto
             * pequeño, y un índice funcional aquí ataría el boilerplate a un
             * motor concreto.
             */
            $table->index(['model_type', 'model_id', 'collection_name'], 'media_model_collection_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
