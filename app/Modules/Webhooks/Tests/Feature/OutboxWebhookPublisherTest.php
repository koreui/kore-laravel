<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Support\OutboxWebhookPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| OutboxWebhookPublisher
|--------------------------------------------------------------------------
|
| El publisher se resuelve a mano (`new`) y no del contenedor: el binding sólo
| existe con el toggle encendido, y lo que se prueba aquí es la clase, no el
| provider (de eso se ocupa WebhooksToggleTest).
|
*/

beforeEach(function (): void {
    Config::set('kore-webhooks.events', [
        'orders.created' => 'Se creó un pedido',
        'orders.paid' => 'Se pagó un pedido',
    ]);
});

it('escribe una fila por endpoint activo y suscrito', function (): void {
    $todos = WebhookEndpoint::factory()->create();
    $concreto = WebhookEndpoint::factory()->subscribedTo(['orders.created'])->create();
    WebhookEndpoint::factory()->subscribedTo(['orders.paid'])->create();

    new OutboxWebhookPublisher()->publish('orders.created', ['id' => 7]);

    expect(WebhookDelivery::query()->count())->toBe(2)
        ->and(WebhookDelivery::query()->where('endpoint_id', $todos->id)->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->where('endpoint_id', $concreto->id)->exists())->toBeTrue();
});

it('no le escribe a un endpoint apagado', function (): void {
    // Un endpoint apagado no acumula cola: al encenderlo no recibe de golpe lo
    // que se perdió, y es lo que se quiere.
    WebhookEndpoint::factory()->inactive()->create();

    new OutboxWebhookPublisher()->publish('orders.created', ['id' => 7]);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('deja la entrega lista para salir ya', function (): void {
    WebhookEndpoint::factory()->create();

    new OutboxWebhookPublisher()->publish('orders.created', ['id' => 7, 'total' => '10.00']);

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->event)->toBe('orders.created')
        ->and($delivery->payload)->toBe(['id' => 7, 'total' => '10.00'])
        ->and($delivery->uuid)->not->toBeNull()
        ->and($delivery->next_attempt_at)->not->toBeNull();
});

it('dispara un WebhookDeliveryQueued por fila escrita', function (): void {
    Event::fake([WebhookDeliveryQueued::class]);

    WebhookEndpoint::factory()->count(2)->create();

    new OutboxWebhookPublisher()->publish('orders.created', []);

    Event::assertDispatchedTimes(WebhookDeliveryQueued::class, 2);
});

it('lanza si el evento no está en el catálogo', function (): void {
    // Un nombre mal escrito no puede fallar en silencio: el suscriptor no
    // recibiría nada y nadie se enteraría hasta que lo reclamara.
    WebhookEndpoint::factory()->create();

    expect(fn (): mixed => new OutboxWebhookPublisher()->publish('orders.inventado', []))
        ->toThrow(InvalidArgumentException::class);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('se va con la transacción del dominio si ésta se revierte', function (): void {
    // La razón de ser del outbox: publicar dentro de la transacción de quien
    // publica. Si el pedido no llega a existir, tampoco sale su webhook.
    WebhookEndpoint::factory()->create();

    try {
        DB::transaction(function (): void {
            new OutboxWebhookPublisher()->publish('orders.created', ['id' => 7]);

            throw new RuntimeException('el pedido falló después de publicar');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    expect(WebhookDelivery::query()->count())->toBe(0);
});
