<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Auth\Events\ApiTokenRevoked;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| Autenticación por token — api/v1/auth/*
|--------------------------------------------------------------------------
|
| Login, refresco y las dos formas de cerrar sesión, contra la pila real: el
| grupo `api` con su `throttle`, `BaseApiRequest` produciendo el 422 del
| contrato y `ApiExceptionRenderer` traduciendo todo lo demás (R54).
|
| El limiter `api-auth` son 5 peticiones por minuto y por IP, y cuenta también
| las que fallan: por eso cada test que hace login gasta uno de esos cinco. La
| caché en pruebas es el store `array` (phpunit.xml), que nace vacío en cada
| test, así que no hay contaminación entre ellos.
|
*/

/** Cuerpo mínimo válido de login. */
function loginPayload(User $user, array $extra = []): array
{
    return [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone de prueba',
        ...$extra,
    ];
}

/**
 * Olvida los guards ya resueltos entre dos peticiones del mismo test.
 *
 * En producción cada petición trae su propia aplicación; en un test la
 * aplicación es la misma, y el `RequestGuard` de Sanctum se queda con el usuario
 * que resolvió la primera vez. Sin esto, un token revocado seguiría «valiendo»
 * hasta el final del test y la comprobación de que el logout sirve para algo
 * pasaría siempre, mirase lo que mirase.
 */
function forgetResolvedGuards(): void
{
    Auth::forgetGuards();
}

it('emite un token con el envelope del contrato', function (): void {
    $user = User::factory()->create();

    $response = $this->postJson(route('api.v1.auth.login'), loginPayload($user));

    $response->assertCreated()
        ->assertExactJsonStructure([
            'data' => [
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'email', 'roles', 'permissions'],
            ],
        ])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $user->getKey());

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('pone los permisos efectivos del usuario como abilities del token', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->postJson(route('api.v1.auth.login'), loginPayload($admin))->assertCreated();

    $token = PersonalAccessToken::query()->firstOrFail();

    expect($token->abilities)
        ->toBe($admin->getAllPermissions()->pluck('name')->values()->all())
        ->and($token->abilities)->toContain('users.view')
        ->and($token->can('users.view'))->toBeTrue();
});

it('emite un token sin ninguna ability cuando el usuario no tiene permisos', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), loginPayload($user))->assertCreated();

    $token = PersonalAccessToken::query()->firstOrFail();

    // Notarium cae a `['*']` cuando la lista está vacía y le regala el comodín
    // justo a quien no tiene nada. Aquí «no puede nada» significa `[]`.
    expect($token->abilities)->toBe([])
        ->and($token->can('users.view'))->toBeFalse();
});

it('caduca el token según kore-api.tokens.expires_minutes', function (): void {
    config(['kore-api.tokens.expires_minutes' => 60]);

    $user = User::factory()->create();

    $response = $this->postJson(route('api.v1.auth.login'), loginPayload($user))->assertCreated();

    $token = PersonalAccessToken::query()->firstOrFail();

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(60)
        ->and($response->json('data.expires_at'))->toBe($token->expires_at->toIso8601String());
});

it('emite un token sin caducidad con expires_minutes en null', function (): void {
    config(['kore-api.tokens.expires_minutes' => null]);

    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), loginPayload($user))
        ->assertCreated()
        ->assertJsonPath('data.expires_at', null);

    expect(PersonalAccessToken::query()->firstOrFail()->expires_at)->toBeNull();
});

it('no toca sanctum.expiration, que sigue siendo global y retroactiva', function (): void {
    expect(config('sanctum.expiration'))->toBeNull();
});

it('dispara ApiTokenIssued con los datos del dispositivo', function (): void {
    Event::fake([ApiTokenIssued::class]);

    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), loginPayload($user, [
        'device_id' => 'ABC-123',
        'platform' => 'ios',
        'app_version' => '1.4.2',
    ]))->assertCreated();

    Event::assertDispatched(ApiTokenIssued::class, fn (ApiTokenIssued $event): bool => $event->user->is($user)
        && $event->tokenName === 'iPhone de prueba'
        && $event->deviceId === 'ABC-123'
        && $event->platform === 'ios'
        && $event->appVersion === '1.4.2');
});

it('rechaza una contraseña equivocada sin decir si el email existe', function (): void {
    $user = User::factory()->create();

    $conPassword = $this->postJson(route('api.v1.auth.login'), loginPayload($user, ['password' => 'otra-cosa']));
    $sinCuenta = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'nadie@example.test',
        'password' => 'password',
        'device_name' => 'iPhone de prueba',
    ]);

    foreach ([$conPassword, $sinCuenta] as $response) {
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email']]]);
    }

    // La respuesta es la misma palabra por palabra: si no lo fuera, probar
    // correos contra el login sería un censo de las cuentas que existen.
    expect($conPassword->json('error.details'))->toBe($sinCuenta->json('error.details'));

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('exige device_name', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['device_name']]]);
});

it('rechaza una plataforma fuera de la lista', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), loginPayload($user, ['platform' => 'symbian']))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['platform']]]);
});

it('corta al sexto intento desde la misma IP', function (): void {
    $user = User::factory()->create();

    // Cinco fallos: `throttle:api-auth` corre por delante del controller, así
    // que un intento equivocado consume cupo igual que uno bueno.
    foreach (range(1, 5) as $ignored) {
        $this->postJson(route('api.v1.auth.login'), loginPayload($user, ['password' => 'mal']))
            ->assertStatus(422);
    }

    $this->postJson(route('api.v1.auth.login'), loginPayload($user))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'throttled')
        ->assertHeader('Retry-After');
});

it('niega el login a una cuenta con verificación en dos pasos confirmada', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secreto'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->postJson(route('api.v1.auth.login'), loginPayload($user))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'two_factor_required')
        ->assertJsonPath('error.message', 'Esta cuenta tiene verificación en dos pasos: inicia sesión desde el navegador.');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('deja entrar a quien empezó a activar el 2FA pero no lo confirmó', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secreto'),
        'two_factor_confirmed_at' => null,
    ]);

    $this->postJson(route('api.v1.auth.login'), loginPayload($user))->assertCreated();
});

it('cierra la sesión de este dispositivo y deja de valer el token', function (): void {
    Event::fake([ApiTokenRevoked::class]);

    $user = User::factory()->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->postJson(route('api.v1.auth.logout'))->assertNoContent();

    forgetResolvedGuards();

    $this->withToken($token)->getJson(route('api.v1.user.me'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');

    Event::assertDispatched(ApiTokenRevoked::class, fn (ApiTokenRevoked $event): bool => $event->user->is($user)
        && $event->tokenId !== null
        && $event->reason === 'logout');
});

it('el logout deja intactos los tokens de los otros dispositivos', function (): void {
    $user = User::factory()->create();
    $movil = $user->createToken('Móvil')->plainTextToken;
    $tablet = $user->createToken('Tablet')->plainTextToken;

    $this->withToken($movil)->postJson(route('api.v1.auth.logout'))->assertNoContent();

    forgetResolvedGuards();

    $this->withToken($tablet)->getJson(route('api.v1.user.me'))->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('cierra la sesión en todos los dispositivos', function (): void {
    Event::fake([ApiTokenRevoked::class]);

    $user = User::factory()->create();
    $movil = $user->createToken('Móvil')->plainTextToken;
    $tablet = $user->createToken('Tablet')->plainTextToken;

    $this->withToken($movil)->postJson(route('api.v1.auth.logout-all'))->assertNoContent();

    expect(PersonalAccessToken::query()->count())->toBe(0);

    forgetResolvedGuards();

    $this->withToken($tablet)->getJson(route('api.v1.user.me'))->assertStatus(401);

    Event::assertDispatched(ApiTokenRevoked::class, fn (ApiTokenRevoked $event): bool => $event->user->is($user)
        && $event->tokenId === null
        && $event->reason === 'logout_all');
});

it('rota el token en el refresco y retira el que se usó', function (): void {
    $user = User::factory()->create();
    $viejo = $user->createToken('Móvil')->plainTextToken;

    $response = $this->withToken($viejo)->postJson(route('api.v1.auth.refresh'))->assertCreated();

    $nuevo = (string) $response->json('data.token');

    expect($nuevo)->not->toBe($viejo)
        // El nombre del dispositivo sobrevive: el usuario sigue viendo «Móvil»
        // en su lista de sesiones y no un token nuevo sin identificar.
        ->and(PersonalAccessToken::query()->firstOrFail()->name)->toBe('Móvil')
        ->and(PersonalAccessToken::query()->count())->toBe(1);

    forgetResolvedGuards();

    $this->withToken($viejo)->getJson(route('api.v1.user.me'))->assertStatus(401);

    forgetResolvedGuards();

    $this->withToken($nuevo)->getJson(route('api.v1.user.me'))->assertOk();
});

it('el refresco recalcula las abilities desde los permisos de ahora', function (): void {
    $this->seed(ModulesSeeder::class);

    $user = User::factory()->create();
    $user->givePermissionTo('users.view');

    // Un token con abilities rancias, como el que quedaría de una versión
    // anterior del catálogo de permisos.
    $viejo = $user->createToken('Móvil', ['permiso.que.ya.no.existe'])->plainTextToken;

    $nuevo = (string) $this->withToken($viejo)
        ->postJson(route('api.v1.auth.refresh'))
        ->assertCreated()
        ->json('data.token');

    // El refresco NO copia las abilities del token que se presenta: las vuelve
    // a calcular desde `getAllPermissions()`.
    expect(PersonalAccessToken::findToken($nuevo)?->abilities)->toBe(['users.view']);
});

it('el refresco dispara los dos eventos', function (): void {
    Event::fake([ApiTokenIssued::class, ApiTokenRevoked::class]);

    $user = User::factory()->create();
    $token = $user->createToken('Móvil');

    $this->withToken($token->plainTextToken)->postJson(route('api.v1.auth.refresh'))->assertCreated();

    Event::assertDispatched(ApiTokenIssued::class, fn (ApiTokenIssued $event): bool => $event->tokenName === 'Móvil');
    Event::assertDispatched(ApiTokenRevoked::class, fn (ApiTokenRevoked $event): bool => $event->reason === 'refresh'
        && $event->tokenId === (int) $token->accessToken->getKey());
});

it('rechaza al invitado en las rutas con token', function (): void {
    foreach (['api.v1.auth.logout', 'api.v1.auth.logout-all', 'api.v1.auth.refresh'] as $name) {
        $this->postJson(route($name))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
});

it('publica el alias api.v1.auth.me sobre el mismo endpoint que api.v1.user.me', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('Móvil')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->getKey())
        ->assertExactJsonStructure(['data' => ['id', 'name', 'email', 'roles', 'permissions']]);

    expect(route('api.v1.auth.me', absolute: false))->toBe('/api/v1/auth/me');
});
