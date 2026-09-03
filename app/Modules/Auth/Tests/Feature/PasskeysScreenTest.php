<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Http\Livewire\Passkeys;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Pantalla /user/passkeys
|--------------------------------------------------------------------------
|
| Las ceremonias WebAuthn no se pueden simular sin navegador (eso lo cubre
| `tests/e2e/specs/auth/passkeys.spec.ts` con el autenticador virtual de CDP).
| Lo que sí se puede —y es donde están los agujeros— es el acceso: quién entra
| a la pantalla, qué lista y qué puede borrar.
|
*/

/**
 * Una credencial cualquiera del usuario. El paquete no trae factory, y la
 * ceremonia real no hace falta: lo que se prueba aquí es la autorización.
 */
function fakePasskey(User $user, string $name = 'MacBook'): Passkey
{
    /** @var Passkey $passkey */
    $passkey = $user->passkeys()->create([
        'name' => $name,
        'credential_id' => Str::random(43),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);

    return $passkey;
}

/** Marca la sesión como «contraseña confirmada hace un momento». */
function confirmPassword(): void
{
    session(['auth.password_confirmed_at' => time()]);
}

function verifiedUser(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

it('sends a guest to the login screen', function (): void {
    $this->get('/user/passkeys')->assertRedirect(route('login'));
});

it('sends an unverified user to the email verification notice', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)->get('/user/passkeys')->assertRedirect(route('verification.notice'));
});

it('asks a verified user to confirm the password before showing the screen', function (): void {
    $user = verifiedUser();

    $this->actingAs($user)
        ->get('/user/passkeys')
        ->assertRedirect(route('password.confirm'));
});

it('renders the screen with an empty list once the password is confirmed', function (): void {
    $user = verifiedUser();

    confirmPassword();

    $this->actingAs($user)
        ->get('/user/passkeys')
        ->assertOk()
        ->assertSee(__('Todavía no tienes passkeys'))
        ->assertSeeLivewire(Passkeys::class);
});

it('lists only the passkeys of the current user', function (): void {
    $user = verifiedUser();
    $other = verifiedUser();

    fakePasskey($user, 'Mi portátil');
    fakePasskey($other, 'El portátil del vecino');

    confirmPassword();

    Livewire::actingAs($user)
        ->test(Passkeys::class)
        ->assertOk()
        ->assertSee('Mi portátil')
        ->assertDontSee('El portátil del vecino');
});

it('deletes a passkey of its owner', function (): void {
    $user = verifiedUser();
    $passkey = fakePasskey($user);

    confirmPassword();

    Livewire::actingAs($user)
        ->test(Passkeys::class)
        ->call('deletePasskey', $passkey->id)
        ->assertOk();

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeFalse();
});

it('does not let anyone delete a passkey that is not theirs', function (): void {
    $user = verifiedUser();
    $victim = verifiedUser();
    $passkey = fakePasskey($victim);

    confirmPassword();

    Livewire::actingAs($user)
        ->test(Passkeys::class)
        ->call('deletePasskey', $passkey->id)
        ->assertNotFound();

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue();
});

/*
 * El `Gate::before` de AuthModuleServiceProvider devuelve true para el
 * superadmin ante CUALQUIER ability, así que la policy no lo frena. Lo que lo
 * frena es que la credencial se busca dentro de `$user->passkeys()`: una llave
 * ajena ni siquiera aparece.
 */
it('does not let a superadmin delete somebody else passkey either', function (): void {
    $superadmin = verifiedUser();
    $superadmin->assignRole(Role::findOrCreate(Role::SUPERADMIN));

    $victim = verifiedUser();
    $passkey = fakePasskey($victim);

    confirmPassword();

    Livewire::actingAs($superadmin)
        ->test(Passkeys::class)
        ->call('deletePasskey', $passkey->id)
        ->assertNotFound();

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue();
});

/*
 * R23 · `/livewire/update` no pasa por el middleware `password.confirm` de la
 * ruta, así que la ventana de confirmación puede caducar con la pantalla
 * abierta. El componente la vuelve a comprobar.
 */
it('refuses to delete when the password confirmation has expired', function (): void {
    $user = verifiedUser();
    $passkey = fakePasskey($user);

    session(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout', 10800) - 60]);

    Livewire::actingAs($user)
        ->test(Passkeys::class)
        ->call('deletePasskey', $passkey->id)
        ->assertStatus(423);

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue();
});

/*
 * Con el toggle apagado la URL no existe: 404 como cualquier ruta inexistente,
 * no un 403 ni una redirección que delate que la pantalla está ahí escondida.
 *
 * Sin usuario a propósito: `withEnvironment()` rearranca la aplicación y con
 * ella la SQLite `:memory:`, así que dentro del callback no hay base. Un
 * invitado basta — si la ruta existiera, el middleware `auth` lo mandaría a
 * `/login` y la aserción fallaría.
 */
it('is a 404 when the toggle is off', function (): void {
    withEnvironment(['AUTH_PASSKEYS' => 'false'], function (): void {
        $this->get('/user/passkeys')->assertNotFound();
    });
});
