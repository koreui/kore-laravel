<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;

/**
 * Credenciales WebAuthn (passkeys).
 *
 * Publicada desde `laravel/passkeys` (`vendor:publish --tag=fortify-migrations`)
 * y adaptada al boilerplate: `declare(strict_types=1)` y `down()` explícito
 * (R29). Vive en `database/migrations/` y no en el módulo Auth porque cuelga de
 * `App\Models\User`, que es el único modelo global.
 *
 * La tabla se crea aunque `AUTH_PASSKEYS=false`: el toggle apaga las rutas y la
 * pantalla, no el esquema. Una migración condicional dejaría bases distintas
 * según el `.env` del día en que se migró, que es exactamente lo que un
 * boilerplate reutilizable no puede permitirse.
 *
 * `Passkeys::userModel()` lo fija Fortify en su `register()`
 * (`LaravelPasskeys::useUserModel(...)`) leyendo el provider del guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
