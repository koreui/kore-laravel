<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de invitación: la puerta del registro cuando `AUTH_INVITATIONS` está
 * encendido.
 *
 * Existe para que una aplicación pueda tener el `/register` publicado sin que
 * cualquiera que llegue a la URL se convierta en usuario: el código se reparte
 * a quien se quiere dentro, y trae escrito con qué rol entra.
 *
 * La tabla se migra **siempre**, también con el toggle apagado: un toggle apaga
 * rutas y comportamiento, nunca la forma de la base
 * (`docs/architecture/toggles.md`). El precedente es `AUTH_PASSKEYS=false`, que
 * deja la tabla `passkeys` en su sitio.
 *
 * Un código no se gasta desapareciendo: se le llevan los usos y se comparan con
 * `max_uses`, así queda el rastro de cuánta gente entró por cada campaña. Y no
 * se «desactiva» con un booleano — revocar es adelantar `expires_at`, que es un
 * estado menos que mantener y una fecha más que auditar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_codes', function (Blueprint $table): void {
            $table->id();

            // Se guarda y se compara SIEMPRE normalizado (mayúsculas, sin
            // espacios): quien lo teclea no debe fallar por un cambio de caja.
            $table->string('code', 32)->unique();

            // Rol con el que nace quien lo usa. Es el `name` de un rol del
            // catálogo (`App\Core\Enums\SystemRole`), no una FK: los roles se
            // siembran por nombre y una FK contra `roles` ataría esta tabla al
            // paquete de permisos.
            $table->string('role');

            // NULL = sin límite. `uses` vive en la fila para poder cerrar el
            // código dentro de la misma transacción que da el alta.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);

            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Para qué es el código («Equipo de soporte», «Alta de octubre»).
            $table->string('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_codes');
    }
};
