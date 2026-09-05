<?php

declare(strict_types=1);

use App\Core\Contracts\WebhookPublisher;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Webhooks\Console\Commands\WebhooksDispatchCommand;
use App\Modules\Webhooks\Console\Commands\WebhooksPruneCommand;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Support\OutboxWebhookPublisher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| WEBHOOKS_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra nada
| observable: ni el binding de `WebhookPublisher`, ni las rutas, ni el alias
| `webhook.signed`, ni los listeners, ni los comandos, ni sus entradas en el
| scheduler.
|
| La excepción documentada es el ESQUEMA: las dos tablas se migran igual, porque
| un toggle apaga rutas y comportamiento, nunca la forma de la base.
|
| La suite corre con el toggle apagado (`phpunit.xml` lo fuerza), así que los
| casos "encendido" arrancan la aplicación de nuevo con `withEnvironment()`
| (tests/Pest.php).
|
*/

/**
 * Arranca la aplicación con el módulo encendido.
 */
function withWebhooksToggleOn(Closure $callback): void
{
    withEnvironment(['WEBHOOKS_ENABLED' => 'true'], $callback);
}

/**
 * Nombres de los comandos programados en el scheduler.
 *
 * @return Collection<int, string>
 */
function webhooksScheduledCommands(): Collection
{
    return collect(resolve(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command);
}

/*
|--------------------------------------------------------------------------
| Toggle apagado (estado por defecto de la suite)
|--------------------------------------------------------------------------
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.webhooks.enabled'))->toBeFalse();
});

it('does not bind the WebhookPublisher contract with the toggle off', function (): void {
    // Resolverlo tiene que LANZAR: «esta instalación no manda webhooks» es una
    // respuesta, y un publisher que traga en silencio no lo es.
    expect(fn (): mixed => resolve(WebhookPublisher::class))
        ->toThrow(BindingResolutionException::class);
});

it('registers no webhook routes with the toggle off', function (): void {
    expect(Route::has('webhooks.index'))->toBeFalse();

    $this->get('/webhooks')->assertNotFound();
});

it('does not register the commands with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))
        ->not->toContain('webhooks:dispatch')
        ->not->toContain('webhooks:prune');
});

it('schedules nothing webhook related with the toggle off', function (): void {
    expect(webhooksScheduledCommands()->contains(fn (string $command): bool => str_contains($command, 'webhooks:')))
        ->toBeFalse();
});

it('listens to no event with the toggle off', function (): void {
    // Ni el propio del outbox ni el de Auth: apagar el módulo tiene que dejar el
    // login por API exactamente como estaba.
    expect(Event::hasListeners(WebhookDeliveryQueued::class))->toBeFalse()
        ->and(Event::hasListeners(ApiTokenIssued::class))->toBeFalse();
});

it('does not register the webhook.signed alias with the toggle off', function (): void {
    expect(resolve('router')->getMiddleware())->not->toHaveKey('webhook.signed');
});

it('migrates both tables even with the toggle off', function (): void {
    // El esquema NO depende del toggle: si dependiera, encender WEBHOOKS_ENABLED
    // en producción exigiría una migración a mano con tráfico encima.
    expect(config('kore-app.webhooks.enabled'))->toBeFalse()
        ->and(Schema::hasTable('webhook_endpoints'))->toBeTrue()
        ->and(Schema::hasColumns('webhook_endpoints', [
            'uuid', 'name', 'url', 'secret', 'subscribed_events', 'active', 'created_by',
        ]))->toBeTrue()
        ->and(Schema::hasTable('webhook_deliveries'))->toBeTrue()
        ->and(Schema::hasColumns('webhook_deliveries', [
            'uuid', 'endpoint_id', 'event', 'payload', 'attempts', 'status',
            'next_attempt_at', 'delivered_at', 'last_error', 'response_status',
        ]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('binds the WebhookPublisher contract with the toggle on', function (): void {
    withWebhooksToggleOn(function (): void {
        expect(resolve(WebhookPublisher::class))->toBeInstanceOf(OutboxWebhookPublisher::class)
            ->and(resolve(WebhookPublisher::class))->toBe(resolve(WebhookPublisher::class));
    });
});

it('registers the four screens with the toggle on', function (): void {
    withWebhooksToggleOn(function (): void {
        expect(Route::has('webhooks.index'))->toBeTrue()
            ->and(Route::has('webhooks.create'))->toBeTrue()
            ->and(Route::has('webhooks.show'))->toBeTrue()
            ->and(Route::has('webhooks.edit'))->toBeTrue();
    });
});

it('registers both commands and schedules them with the toggle on', function (): void {
    withWebhooksToggleOn(function (): void {
        expect(array_keys(Artisan::all()))->toContain('webhooks:dispatch', 'webhooks:prune')
            ->and(Artisan::all()['webhooks:dispatch'])->toBeInstanceOf(WebhooksDispatchCommand::class)
            ->and(Artisan::all()['webhooks:prune'])->toBeInstanceOf(WebhooksPruneCommand::class)
            ->and(webhooksScheduledCommands()->contains(fn (string $c): bool => str_contains($c, 'webhooks:dispatch')))->toBeTrue()
            ->and(webhooksScheduledCommands()->contains(fn (string $c): bool => str_contains($c, 'webhooks:prune')))->toBeTrue();
    });
});

it('listens to its outbox event and to the Auth token event with the toggle on', function (): void {
    withWebhooksToggleOn(function (): void {
        expect(Event::hasListeners(WebhookDeliveryQueued::class))->toBeTrue()
            ->and(Event::hasListeners(ApiTokenIssued::class))->toBeTrue();
    });
});

it('registers the webhook.signed alias with the toggle on', function (): void {
    withWebhooksToggleOn(function (): void {
        expect(resolve('router')->getMiddleware())->toHaveKey('webhook.signed');
    });
});
