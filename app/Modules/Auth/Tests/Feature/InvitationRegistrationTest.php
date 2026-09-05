<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\InvitationCode;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Registro con código de invitación (AUTH_INVITATIONS=true)
|--------------------------------------------------------------------------
|
| Aquí basta `Config::set()` y NO hace falta `withEnvironment()`: la ruta
| `register` la publica Fortify siempre, y quien decide si se pide código es
| `Auth\Fortify\CreateNewUser`, que lee el toggle **en tiempo de petición**. Es
| el primer caso de la tabla de `docs/patterns/test-con-otro-entorno.md`.
|
| Y además es el único que sirve: `InvitationRedeemAction` abre una transacción,
| y dentro de un `withEnvironment()` la conexión rearrancada tiene el PDO del
| test —ya transaccionado por `RefreshDatabase`— con su contador de
| transacciones a cero, así que SQLite responde «cannot start a transaction
| within a transaction». Lo que este archivo prueba no necesita rearrancar nada.
|
| Lo que sí necesita arranque —rutas, middleware, componentes— vive en
| `InvitationsToggleTest` y en `AccountStatusMiddlewareTest`.
|
*/

/** @param array<string, string> $overrides */
function registerWith(array $overrides = []): TestResponse
{
    return test()->post(route('register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    (new ModulesSeeder)->run();

    Config::set('kore-app.auth.invitations', true);
});

it('creates the account with the role written on the code', function (): void {
    $code = InvitationCode::factory()->forRole(SystemRole::Admin)->create();

    registerWith(['invitation_code' => $code->code])->assertRedirect();

    $user = User::where('email', 'ada@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user?->accountStatus())->toBe(AccountStatus::Active)
        ->and($user?->activated_at)->not->toBeNull()
        ->and($user?->hasRole(SystemRole::Admin->value))->toBeTrue()
        ->and($code->fresh()?->uses)->toBe(1);
});

it('accepts a code typed in lowercase and with spaces', function (): void {
    $code = InvitationCode::factory()->create(['code' => 'KORE2026']);

    registerWith(['invitation_code' => ' kore 2026 '])->assertRedirect();

    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue()
        ->and($code->fresh()?->uses)->toBe(1);
});

it('rejects a registration without any code', function (): void {
    registerWith()->assertSessionHasErrors('invitation_code');

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('rejects an unknown code', function (): void {
    registerWith(['invitation_code' => 'NOEXISTE'])->assertSessionHasErrors('invitation_code');

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('rejects an expired code and says so', function (): void {
    $code = InvitationCode::factory()->expired()->create();

    registerWith(['invitation_code' => $code->code])
        ->assertSessionHasErrors(['invitation_code' => 'Este código de invitación ya caducó.']);

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('rejects an exhausted code and says so', function (): void {
    $code = InvitationCode::factory()->exhausted()->create();

    registerWith(['invitation_code' => $code->code])
        ->assertSessionHasErrors(['invitation_code' => 'Este código de invitación ya alcanzó su límite de registros.']);

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('stops accepting registrations once the quota is full', function (): void {
    $code = InvitationCode::factory()->create(['max_uses' => 1]);

    registerWith(['invitation_code' => $code->code])->assertRedirect();

    auth()->logout();

    registerWith(['email' => 'grace@example.com', 'invitation_code' => $code->code])
        ->assertSessionHasErrors('invitation_code');

    expect(User::where('email', 'grace@example.com')->exists())->toBeFalse()
        ->and($code->fresh()?->uses)->toBe(1);
});
