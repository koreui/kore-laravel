<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| webhooks:dispatch y webhooks:prune
|--------------------------------------------------------------------------
|
| Los comandos sólo existen con el toggle encendido, así que cada test arranca
| la aplicación con `withEnvironment()` (tests/Pest.php).
|
*/

/**
 * Corre el callback con el módulo encendido y sin peticiones reales.
 */
function withWebhooksCommands(Closure $callback): void
{
    withEnvironment(['WEBHOOKS_ENABLED' => 'true'], function () use ($callback): void {
        Http::preventStrayRequests();

        $callback();
    });
}

it('webhooks:dispatch entrega lo que ya venció y deja lo que no', function (): void {
    withWebhooksCommands(function (): void {
        Http::fake(['example.test/*' => Http::response('', 200)]);

        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);

        $vencida = WebhookDelivery::factory()->create([
            'endpoint_id' => $endpoint->id,
            'next_attempt_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $futura = WebhookDelivery::factory()->retryingIn(3600)->create([
            'endpoint_id' => $endpoint->id,
        ]);

        $this->artisan('webhooks:dispatch')->assertSuccessful();

        expect($vencida->refresh()->status)->toBe(DeliveryStatus::Delivered)
            ->and($futura->refresh()->status)->toBe(DeliveryStatus::Failed);

        Http::assertSentCount(1);
    });
});

it('webhooks:dispatch no toca lo ya cerrado', function (): void {
    withWebhooksCommands(function (): void {
        Http::fake();

        WebhookDelivery::factory()->delivered()->create();
        WebhookDelivery::factory()->exhausted()->create();

        $this->artisan('webhooks:dispatch')->assertSuccessful();

        Http::assertNothingSent();
    });
});

it('webhooks:dispatch --dry-run cuenta y no manda nada', function (): void {
    withWebhooksCommands(function (): void {
        Http::fake();

        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);
        WebhookDelivery::factory()->count(2)->create(['endpoint_id' => $endpoint->id]);

        $this->artisan('webhooks:dispatch', ['--dry-run' => true])
            ->expectsOutputToContain('se intentarían 2 entrega(s)')
            ->assertSuccessful();

        Http::assertNothingSent();

        expect(WebhookDelivery::query()->where('status', '=', DeliveryStatus::Pending->value)->count())
            ->toBe(2);
    });
});

it('webhooks:dispatch respeta el tope de la pasada', function (): void {
    withWebhooksCommands(function (): void {
        // El tope es lo que evita que una caída larga del receptor produzca una
        // ráfaga de miles de peticiones cuando vuelve.
        Config::set('kore-webhooks.dispatch_batch', 2);
        Http::fake(['example.test/*' => Http::response('', 200)]);

        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://example.test/hooks']);
        WebhookDelivery::factory()->count(5)->create(['endpoint_id' => $endpoint->id]);

        $this->artisan('webhooks:dispatch')->assertSuccessful();

        Http::assertSentCount(2);
    });
});

it('webhooks:prune borra las cerradas viejas', function (): void {
    withWebhooksCommands(function (): void {
        WebhookDelivery::factory()->delivered(daysAgo: 40)->create();
        WebhookDelivery::factory()->retryingIn(600)->create();

        $this->artisan('webhooks:prune', ['--days' => 30])->assertSuccessful();

        expect(WebhookDelivery::query()->count())->toBe(1);
    });
});

it('webhooks:prune --dry-run no borra nada', function (): void {
    withWebhooksCommands(function (): void {
        WebhookDelivery::factory()->count(3)->delivered(daysAgo: 40)->create();

        $this->artisan('webhooks:prune', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('se borrarían 3 entrega(s)')
            ->assertSuccessful();

        expect(WebhookDelivery::query()->count())->toBe(3);
    });
});
