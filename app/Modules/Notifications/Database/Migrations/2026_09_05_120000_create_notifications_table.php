<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla estándar del canal `database` de Laravel.
 *
 * Es la que genera `php artisan make:notifications-table`, movida al módulo y
 * conservada tal cual —incluido `data` como texto con el JSON dentro— para que
 * `Notifiable::notifications()`, `unreadNotifications()` y `markAsRead()` sigan
 * funcionando sin una línea de código propio. Lo nuestro no es la tabla: es la
 * forma de ese JSON, que fija `App\Core\Data\NotificationData`.
 *
 * **Se migra siempre, también con `NOTIFICATIONS_ENABLED=false`.** Un toggle
 * apaga rutas y comportamiento, nunca la forma de la base
 * (`docs/architecture/toggles.md`): si la migración fuera condicional, dos
 * instalaciones del mismo commit tendrían bases distintas según el `.env` del
 * día en que se migró, y encender el módulo en producción exigiría una
 * migración a mano justo cuando ya hay tráfico. El precedente es
 * `AUTH_PASSKEYS=false`, que deja de registrar la pantalla mientras la tabla
 * `passkeys` se migra igual.
 *
 * El índice extra es el que sostiene la única consulta que hace cada pantalla
 * del módulo: «lo de esta persona, lo no leído primero, lo más reciente
 * arriba».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
                'notifications_inbox_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
