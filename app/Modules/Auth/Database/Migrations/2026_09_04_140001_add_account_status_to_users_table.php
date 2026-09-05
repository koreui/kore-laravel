<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de alta de la cuenta: `pending` · `active` · `suspended`
 * ({@see AccountStatus}).
 *
 * Se **añaden** dos columnas a una tabla que ya existe; no se modifica ninguna,
 * así que aquí no hay ningún `->change()` que pudiera perder atributos en
 * silencio (R53). El `down()` las quita, y sólo ellas.
 *
 * El default es `active` a propósito: sin `AUTH_INVITATIONS` la columna existe
 * pero no gobierna nada, y toda cuenta —las que ya había cuando se corre esta
 * migración incluidas— tiene que seguir entrando exactamente igual. Encender el
 * toggle en una instalación viva no deja a nadie fuera; sólo cambia por dónde
 * nacen las cuentas nuevas.
 *
 * `activated_at` es nullable porque las cuentas anteriores a esta migración no
 * tienen una fecha de activación que inventarles: un `now()` allí sería una
 * fecha falsa en una columna de auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status')->default(AccountStatus::Active->value)->index();
            $table->timestamp('activated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'activated_at']);
        });
    }
};
