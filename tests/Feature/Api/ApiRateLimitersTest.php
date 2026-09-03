<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| R28 · los tres limiters nombrados de la API
|--------------------------------------------------------------------------
|
| Un limiter que no existe no falla: `throttle:api-auth` con un limiter sin
| registrar degrada a `maxAttempts = (int) 'api-auth' = 0` y bloquea TODAS las
| peticiones, o —según la versión— deja de limitar. Las dos formas de fallar
| son silenciosas, así que el test comprueba que los tres están y con qué cifra.
|
*/

/**
 * Resuelve un limiter contra una petición y devuelve el `Limit` resultante.
 */
function resolveLimit(string $name, ?User $user = null): Limit
{
    $limiter = RateLimiter::limiter($name);

    expect($limiter)->not->toBeNull("El limiter «{$name}» no está registrado");

    $request = Request::create('/api/v1/probe', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

    if ($user instanceof User) {
        $request->setUserResolver(fn (): User => $user);
    }

    /** @var Limit $limit */
    $limit = $limiter($request);

    return $limit;
}

it('registra los tres limiters de la API con las cifras de kore-api', function (): void {
    /** @var array<string, int> $configuradas */
    $configuradas = config('kore-api.limiters');

    expect($configuradas)->toBe([
        'api' => 60,
        'api-auth' => 5,
        'api-uploads' => 30,
    ]);

    foreach ($configuradas as $name => $perMinute) {
        expect(resolveLimit($name)->maxAttempts)
            ->toBe($perMinute, "El limiter «{$name}» no usa la cifra de config/kore-api.php");
    }
});

it('agrupa api y api-uploads por usuario cuando hay sesión', function (): void {
    $user = User::factory()->create();

    foreach (['api', 'api-uploads'] as $name) {
        expect(resolveLimit($name, $user)->key)->toBe((string) $user->getKey())
            ->and(resolveLimit($name)->key)->toBe('203.0.113.7');
    }
});

it('agrupa api-auth por IP aunque haya usuario', function (): void {
    $user = User::factory()->create();

    // A propósito: quien fuerza credenciales todavía no tiene usuario, así que
    // limitar por usuario no limitaría nada.
    expect(resolveLimit('api-auth', $user)->key)->toBe('203.0.113.7');
});

it('aplica throttle:api-auth de verdad sobre una ruta', function (): void {
    Route::middleware(['api', 'throttle:api-auth'])
        ->get('/api/probe/login', fn (): array => ['ok' => true]);

    foreach (range(1, 5) as $intento) {
        $this->getJson('/api/probe/login')->assertOk("El intento {$intento} debería pasar");
    }

    $this->getJson('/api/probe/login')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'throttled');
});

it('devuelve las cabeceras de rate limit en una petición autenticada', function (): void {
    Route::middleware('api')->get('/api/probe/limited', fn (): array => ['ok' => true]);

    $this->getJson('/api/probe/limited')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60)
        ->assertHeader('X-RateLimit-Remaining', 59);
});
