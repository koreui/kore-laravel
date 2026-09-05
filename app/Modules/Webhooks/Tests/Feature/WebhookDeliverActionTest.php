<?php

declare(strict_types=1);

use App\Core\Support\WebhookSignature;
use App\Modules\Webhooks\Actions\WebhookDeliverAction;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| WebhookDeliverAction
|--------------------------------------------------------------------------
|
| Ninguna petición sale de verdad: `Http::fake()` intercepta. Lo que se prueba
| es lo que la Action DECIDE con cada respuesta, que es lo único que se ve
| después en la pantalla y en la tabla.
|
*/

beforeEach(function (): void {
    Config::set('kore-webhooks.max_attempts', 3);
    Config::set('kore-webhooks.backoff', [60, 300]);
    Config::set('kore-webhooks.events', ['orders.created' => 'Se creó un pedido']);
});

/**
 * Una entrega lista para salir, con su endpoint.
 */
function webhookDeliveryFor(string $url = 'https://example.test/hooks'): WebhookDelivery
{
    $endpoint = WebhookEndpoint::factory()->create([
        'url' => $url,
        'secret' => 'secreto-de-prueba',
    ]);

    return WebhookDelivery::factory()->create([
        'endpoint_id' => $endpoint->id,
        'event' => 'orders.created',
        'payload' => ['id' => 7],
    ]);
}

it('marca la entrega como entregada con un 2xx', function (): void {
    Http::fake(['example.test/*' => Http::response('', 204)]);

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(204)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toBeNull();
});

it('firma el cuerpo exacto que manda, y la firma verifica', function (): void {
    // Es la prueba de que el receptor puede creerse lo que le llega: se
    // reconstruye la verificación con el mismo secreto y el mismo cuerpo.
    Http::fake(['example.test/*' => Http::response('', 200)]);

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    Http::assertSent(function (Request $request) use ($delivery): bool {
        $parsed = WebhookSignature::parse($request->header('X-Kore-Signature')[0] ?? '');

        expect($parsed)->not->toBeNull()
            ->and($request->header('X-Kore-Event')[0] ?? null)->toBe('orders.created')
            ->and($request->header('X-Kore-Delivery')[0] ?? null)->toBe($delivery->uuid);

        return WebhookSignature::verify(
            secret: 'secreto-de-prueba',
            timestamp: $parsed['timestamp'],
            body: $request->body(),
            signature: $parsed['signature'],
        );
    });
});

it('manda el envelope con el uuid, el evento y el payload', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    Http::assertSent(function (Request $request) use ($delivery): bool {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        return $body['id'] === $delivery->uuid
            && $body['event'] === 'orders.created'
            && $body['attempt'] === 1
            && $body['data'] === ['id' => 7];
    });
});

it('programa el reintento con el backoff tras un 5xx', function (): void {
    Http::fake(['example.test/*' => Http::response('boom', 503)]);

    $now = CarbonImmutable::parse('2026-09-05 10:00:00');
    CarbonImmutable::setTestNow($now);

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(503)
        ->and($delivery->last_error)->toBe('HTTP 503')
        // Primer valor de `backoff`: 60 segundos.
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-09-05 10:01:00');

    CarbonImmutable::setTestNow();
});

it('usa el segundo tramo del backoff en el segundo fallo', function (): void {
    Http::fake(['example.test/*' => Http::response('boom', 500)]);

    $now = CarbonImmutable::parse('2026-09-05 10:00:00');
    CarbonImmutable::setTestNow($now);

    $delivery = webhookDeliveryFor();

    $action = resolve(WebhookDeliverAction::class);
    $action->handle($delivery);
    $action->handle($delivery->refresh());

    expect($delivery->refresh()->attempts)->toBe(2)
        // Segundo valor de `backoff`: 300 segundos.
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-09-05 10:05:00');

    CarbonImmutable::setTestNow();
});

it('se agota tras max_attempts y deja de tener cita', function (): void {
    Http::fake(['example.test/*' => Http::response('boom', 500)]);

    $delivery = webhookDeliveryFor();
    $action = resolve(WebhookDeliverAction::class);

    // max_attempts = 3 en este archivo.
    for ($i = 0; $i < 3; $i++) {
        $action->handle($delivery->refresh());
    }

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->next_attempt_at)->toBeNull();
});

it('anota un timeout como fallo sin lanzar', function (): void {
    // Un receptor caído no puede tumbar el worker: la Action no lanza nunca.
    Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->last_error)->toContain('timed out');
});

it('trunca el error a la longitud configurada', function (): void {
    Config::set('kore-webhooks.error_max_length', 20);
    Http::fake(fn (): never => throw new ConnectionException(str_repeat('x', 500)));

    $delivery = webhookDeliveryFor();

    resolve(WebhookDeliverAction::class)->handle($delivery);

    expect(mb_strlen((string) $delivery->refresh()->last_error))->toBe(20);
});

it('no vuelve a mandar una entrega ya cerrada', function (): void {
    // Es el cruce entre el listener en cola y el barrido del scheduler sobre la
    // misma fila: el segundo no puede producir un duplicado.
    Http::fake(['example.test/*' => Http::response('', 200)]);

    $delivery = webhookDeliveryFor();
    $delivery->update(['status' => DeliveryStatus::Delivered]);

    resolve(WebhookDeliverAction::class)->handle($delivery);

    Http::assertNothingSent();
});

it('cierra sin gastar intentos si el endpoint se apagó por el camino', function (): void {
    Http::fake(['example.test/*' => Http::response('', 200)]);

    $delivery = webhookDeliveryFor();
    $delivery->endpoint->update(['active' => false]);

    resolve(WebhookDeliverAction::class)->handle($delivery->refresh());

    Http::assertNothingSent();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->last_error)->toContain('desactivado');
});
