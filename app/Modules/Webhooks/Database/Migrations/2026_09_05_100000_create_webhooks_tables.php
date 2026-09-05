<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los suscriptores de webhooks y el outbox de entregas.
 *
 * Las carga **siempre** `WebhooksModuleServiceProvider`, también con
 * `WEBHOOKS_ENABLED=false`: un toggle apaga rutas y comportamiento, nunca el
 * esquema. Una migración condicional produciría bases distintas según el `.env`
 * del día en que se migró (ver `docs/architecture/toggles.md`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();

            // Identidad pública (App\Core\Concerns\HasPublicUuid): es lo que
            // viaja en la URL de la pantalla, para no publicar cuántas
            // integraciones tiene la instalación.
            $table->uuid('uuid')->nullable()->unique();

            $table->string('name');

            // 2048 es el largo que aceptan los navegadores y los balanceadores
            // como URL; no lleva índice porque nadie busca por ella.
            $table->string('url', 2048);

            // Cifrado en reposo con el cast `encrypted` del modelo: quien lea
            // un dump de la base no puede firmar nada. `text` porque el
            // ciphertext de Laravel pasa holgadamente de 255 caracteres.
            $table->text('secret');

            // Lista de nombres de evento suscritos; `["*"]` significa todos.
            //
            // Se llama `subscribed_events` y no `events` a propósito: `$events` es
            // una propiedad reservada de Eloquent (hoy `$dispatchesEvents`), y un
            // atributo con ese nombre choca con el framework —y con la regla de
            // Rector que vigila ese renombrado, que reescribía cada lectura.
            $table->json('subscribed_events');

            $table->boolean('active')->default(true);

            // `nullOnDelete`: si el usuario que dio de alta la integración se
            // borra, la integración sigue funcionando. Lo que se pierde es a
            // quién preguntarle, no el endpoint.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // El publisher recorre los activos en cada `publish()`.
            $table->index('active');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();

            // Viaja en la cabecera `X-Kore-Delivery`: es la referencia con la
            // que el receptor puede descartar un duplicado y con la que se
            // busca una entrega concreta cuando alguien reclama.
            $table->uuid('uuid')->nullable()->unique();

            $table->foreignId('endpoint_id')
                ->constrained('webhook_endpoints')
                ->cascadeOnDelete();

            $table->string('event', 100);

            // El cuerpo del evento, congelado en el momento de publicar: un
            // reintento de mañana manda lo que pasó hoy, no lo que la fila del
            // dominio diga mañana.
            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');

            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();

            $table->timestamps();

            // El índice que hace barata la consulta de `webhooks:dispatch`:
            // «las que están en juego y ya vencieron». Sin él, cada pasada del
            // scheduler recorre la tabla entera, que es justo la que crece.
            $table->index(['status', 'next_attempt_at']);

            // La pantalla del endpoint lista sus entregas de la más reciente a
            // la más antigua.
            $table->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // En orden inverso: `webhook_deliveries` tiene la FK.
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
