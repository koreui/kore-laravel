<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario de dispositivos que consumen la API.
 *
 * La carga **siempre** `DevicesModuleServiceProvider`, también con
 * `DEVICES_ENABLED=false`: un toggle apaga rutas y comportamiento, nunca el
 * esquema. Una migración condicional produciría bases distintas según el `.env`
 * del día en que se migró (ver `docs/architecture/toggles.md`).
 *
 * El timestamp del nombre va detrás del de `personal_access_tokens`
 * (`2026_05_02_173851`) a propósito: la FK necesita que esa tabla exista, y
 * Laravel ordena todas las migraciones pendientes —las de `database/` y las de
 * los módulos— por nombre de archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();

            // Identidad pública (App\Core\Concerns\HasPublicUuid): es lo que
            // viaja por la API, para no publicar cuántos dispositivos hay.
            $table->uuid('uuid')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Lo elige el cliente y sólo tiene que ser estable para él. 191
            // caracteres es el máximo indexable con utf8mb4 en MySQL viejo.
            $table->string('device_id', 191);

            $table->string('name')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 32)->nullable();

            // Credencial de envío de notificaciones: `text` porque los de FCM y
            // APNs pasan de 255 caracteres. Nunca sale por la API (#[Hidden]).
            $table->text('push_token')->nullable();

            // `nullOnDelete` y no `cascade`: cuando el token caduca o se purga,
            // la fila del dispositivo sobrevive para poder auditar desde dónde
            // se entró. Lo que se pierde es el vínculo, no el registro.
            $table->foreignId('access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Un dispositivo por usuario: volver a entrar reutiliza la fila en
            // vez de acumular una por sesión (el `updateOrCreate` del listener
            // se apoya en esto).
            $table->unique(['user_id', 'device_id']);

            // `devices:cleanup` barre por estas dos columnas.
            $table->index('revoked_at');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
