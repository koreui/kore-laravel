<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Webhooks\Actions\WebhookDeliverAction;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Listeners\DispatchWebhookDelivery;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Los dos listeners
|--------------------------------------------------------------------------
|
| `PublishApiTokenIssued` es la frontera con Auth (R5) y el ejemplo ejecutable
| de cómo un módulo publica lo suyo; `DispatchWebhookDelivery` es el que
| convierte una fila del outbox en una petición.
|
*/

/**
 * Corre el callback con el módulo encendido y sin peticiones reales.
 */
function withWebhooksListeners(Closure $callback): void
{
    withEnvironment(['WEBHOOKS_ENABLED' => 'true'], function () use ($callback): void {
        Http::preventStrayRequests();

        $callback();
    });
}

it('publica auth.api_token.issued cuando Auth emite un token', function (): void {
    withWebhooksListeners(function (): void {
        Http::fake(['example.test/*' => Http::response('', 200)]);

        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);
        $user = User::factory()->create(['email' => 'ada@example.test']);

        Event::dispatch(new ApiTokenIssued(
            user: $user,
            tokenId: 7,
            tokenName: 'iPhone de Ada',
            deviceId: 'abc',
            platform: 'ios',
            appVersion: '1.2.3',
        ));

        $delivery = WebhookDelivery::query()->where('endpoint_id', '=', $endpoint->id)->firstOrFail();

        expect($delivery->event)->toBe('auth.api_token.issued')
            ->and($delivery->payload['user']['id'])->toBe($user->id)
            ->and($delivery->payload['user']['email'])->toBe('ada@example.test')
            ->and($delivery->payload['token']['name'])->toBe('iPhone de Ada')
            ->and($delivery->payload['client']['platform'])->toBe('ios');
    });
});

it('el payload del token no lleva ningún secreto', function (): void {
    // Lo que se pasa a publish() acaba en el servidor de un tercero: un token de
    // API en un log ajeno es una credencial regalada.
    withWebhooksListeners(function (): void {
        Http::fake(['example.test/*' => Http::response('', 200)]);

        WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);
        $user = User::factory()->create();

        Event::dispatch(new ApiTokenIssued(
            user: $user,
            tokenId: 7,
            tokenName: 'CLI',
        ));

        $payload = WebhookDelivery::query()->firstOrFail()->payload;
        $plano = json_encode($payload, JSON_THROW_ON_ERROR);

        expect($payload)->not->toHaveKey('secret')
            ->and($plano)->not->toContain($user->password)
            ->and($plano)->not->toContain('token_id')
            ->and($plano)->not->toContain('plain');
    });
});

it('sin endpoints suscritos, emitir un token no escribe nada', function (): void {
    withWebhooksListeners(function (): void {
        Event::dispatch(new ApiTokenIssued(
            user: User::factory()->create(),
            tokenId: 7,
            tokenName: 'CLI',
        ));

        expect(WebhookDelivery::query()->count())->toBe(0);
    });
});

it('el listener del outbox entrega la fila que se le nombra', function (): void {
    withWebhooksListeners(function (): void {
        Http::fake(['example.test/*' => Http::response('', 202)]);

        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);
        $delivery = WebhookDelivery::factory()->create(['endpoint_id' => $endpoint->id]);

        new DispatchWebhookDelivery(resolve(WebhookDeliverAction::class))
            ->handle(new WebhookDeliveryQueued($delivery->id, $delivery->event));

        expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
            ->and($delivery->response_status)->toBe(202);
    });
});

it('el listener del outbox no revienta si la fila ya no existe', function (): void {
    // La pudo borrar `webhooks:prune`, o el borrado en cascada del endpoint.
    withWebhooksListeners(function (): void {
        Http::fake();

        new DispatchWebhookDelivery(resolve(WebhookDeliverAction::class))
            ->handle(new WebhookDeliveryQueued(9999, 'auth.api_token.issued'));

        Http::assertNothingSent();
    });
});

it('el listener del outbox espera a que la transacción confirme y no reintenta', function (): void {
    // `$afterCommit` es lo que impide entregar un webhook de una transacción que
    // después se revierte, y `#[Tries(1)]` lo que impide que la cola reintente en
    // paralelo al backoff de la propia entrega: serían dos relojes distintos
    // sobre la misma fila, y el receptor recibiría ráfagas.
    $listener = new DispatchWebhookDelivery(resolve(WebhookDeliverAction::class));

    $tries = new ReflectionClass($listener)->getAttributes(Tries::class);

    expect($listener->afterCommit)->toBeTrue()
        ->and($tries)->toHaveCount(1)
        ->and($tries[0]->newInstance()->tries)->toBe(1);
});
