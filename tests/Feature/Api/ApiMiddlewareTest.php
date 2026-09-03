<?php

declare(strict_types=1);

use App\Core\Http\Api\Middleware\ApiAuditLogger;
use App\Core\Http\Api\Middleware\ApiCacheableResponse;
use App\Core\Http\Api\Middleware\ForceJsonResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Psr\Log\LoggerInterface;

/*
|--------------------------------------------------------------------------
| Los tres middleware del contrato de la API
|--------------------------------------------------------------------------
|
| `api.json` y `api.audit` van dentro del grupo `api` (bootstrap/app.php) y
| `api.cache` se pone endpoint a endpoint. Aquí se comprueban las tres cosas:
| que los alias existen, que los dos del grupo están donde deben, y que cada
| uno hace lo que promete.
|
*/

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/probe')->group(function (): void {
        Route::get('/accept', fn (Request $request): array => ['accept' => $request->header('Accept')]);

        Route::get('/cacheable', fn (): array => ['value' => 'estable'])
            ->middleware('api.cache:120');

        Route::get('/cacheable-error', fn () => response()->json(['error' => 'x'], 404))
            ->middleware('api.cache:120');

        Route::post('/cacheable-post', fn (): array => ['value' => 'estable'])
            ->middleware('api.cache:120');

        Route::get('/audited', fn (): array => ['ok' => true])->name('probe.audited');
    });
});

it('registra los tres alias del contrato', function (): void {
    $aliases = resolve('router')->getMiddleware();

    expect($aliases)
        ->toHaveKey('api.json', ForceJsonResponse::class)
        ->toHaveKey('api.cache', ApiCacheableResponse::class)
        ->toHaveKey('api.audit', ApiAuditLogger::class);
});

it('mete api.json y api.audit en el grupo api, por delante del throttle', function (): void {
    /** @var list<string> $group */
    $group = resolve('router')->getMiddlewareGroups()['api'] ?? [];

    expect($group)->toContain(ForceJsonResponse::class)
        ->toContain(ApiAuditLogger::class)
        ->toContain('throttle:api');

    $posiciones = array_flip($group);

    expect($posiciones[ForceJsonResponse::class])->toBeLessThan($posiciones['throttle:api'])
        ->and($posiciones[ApiAuditLogger::class])->toBeLessThan($posiciones['throttle:api']);
});

it('api.json fuerza Accept: application/json aunque el cliente no lo mande', function (): void {
    $this->get('/api/probe/accept')
        ->assertOk()
        ->assertJsonPath('accept', 'application/json');
});

it('api.cache pone ETag y Cache-Control privado en un GET 200', function (): void {
    $response = $this->getJson('/api/probe/cacheable');

    $response->assertOk()->assertHeader('ETag');

    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('max-age=120');
});

it('api.cache devuelve 304 sin cuerpo cuando el ETag coincide', function (): void {
    $etag = (string) $this->getJson('/api/probe/cacheable')->headers->get('ETag');

    $response = $this->getJson('/api/probe/cacheable', ['If-None-Match' => $etag]);

    $response->assertStatus(304);

    expect($response->getContent())->toBe('');
});

it('api.cache no toca una respuesta que no es un GET 200', function (): void {
    $this->getJson('/api/probe/cacheable-error')
        ->assertStatus(404)
        ->assertHeaderMissing('ETag');

    $this->postJson('/api/probe/cacheable-post')
        ->assertOk()
        ->assertHeaderMissing('ETag');
});

it('api.audit escribe una línea por petición en el canal api', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);

    Log::shouldReceive('channel')->with(ApiAuditLogger::CHANNEL)->andReturn($logger);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/probe/audited')->assertOk();

    $logger->shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($user): bool {
            expect($message)->toBe('api.request')
                ->and($context['method'])->toBe('GET')
                ->and($context['path'])->toBe('/api/probe/audited')
                ->and($context['route'])->toBe('probe.audited')
                ->and($context['user_id'])->toBe($user->getKey())
                ->and($context['status'])->toBe(200)
                ->and($context['duration_ms'])->toBeInt();

            return true;
        });
});

it('api.audit nunca escribe el cuerpo de la petición', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);

    Log::shouldReceive('channel')->with(ApiAuditLogger::CHANNEL)->andReturn($logger);

    $this->postJson('/api/probe/cacheable-post', ['password' => 'hunter2'])->assertOk();

    $logger->shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('hunter2');

            return true;
        });
});

it('el canal api existe en config/logging.php', function (): void {
    expect(config('logging.channels.'.ApiAuditLogger::CHANNEL))->toBeArray()
        ->and(config('logging.channels.'.ApiAuditLogger::CHANNEL.'.driver'))->toBe('stack');
});
