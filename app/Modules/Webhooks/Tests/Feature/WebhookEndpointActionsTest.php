<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Webhooks\Actions\WebhookDeliveryPruneAction;
use App\Modules\Webhooks\Actions\WebhookDeliveryRetryAction;
use App\Modules\Webhooks\Actions\WebhookEndpointCreateAction;
use App\Modules\Webhooks\Actions\WebhookEndpointDeleteAction;
use App\Modules\Webhooks\Actions\WebhookEndpointRotateSecretAction;
use App\Modules\Webhooks\Actions\WebhookEndpointUpdateAction;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Las Actions del módulo
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    // `*.example.test` es un TLD reservado que no resuelve, así que la
    // comprobación de red pública de las Actions lo rechazaría y estos tests
    // dejarían de probar lo suyo. Los tests del final la vuelven a
    // encender para comprobar que está y que muerde.
    Config::set('kore-webhooks.allow_private_networks', true);
});

it('crea el endpoint con un secreto generado y cifrado en reposo', function (): void {
    $actor = User::factory()->create();

    $endpoint = resolve(WebhookEndpointCreateAction::class)->handle(
        new WebhookEndpointData(
            name: 'Panel central',
            url: 'https://hub.example.test/hooks',
            events: ['*'],
        ),
        $actor->id,
    );

    expect($endpoint->secret)->toBeString()
        ->and(mb_strlen($endpoint->secret))->toBe(48)
        ->and($endpoint->created_by)->toBe($actor->id)
        ->and($endpoint->uuid)->not->toBeNull();

    // Cifrado en reposo: lo que hay en la columna NO es el secreto. Un dump de
    // la base no basta para firmar nada.
    $raw = DB::table('webhook_endpoints')->where('id', '=', $endpoint->id)->value('secret');

    expect($raw)->not->toBe($endpoint->secret);
});

it('el secreto no sale en una serialización accidental', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    expect($endpoint->toArray())->not->toHaveKey('secret');
});

it('actualiza los datos y deja el secreto intacto', function (): void {
    // Cambiar la URL no invalida la clave compartida: el suscriptor no tiene que
    // reconfigurar nada por mover su receptor de sitio.
    $endpoint = WebhookEndpoint::factory()->create();
    $secret = $endpoint->secret;

    resolve(WebhookEndpointUpdateAction::class)->handle($endpoint, new WebhookEndpointData(
        name: 'Otro nombre',
        url: 'https://otro.example.test/hooks',
        events: ['auth.api_token.issued'],
        active: false,
    ));

    expect($endpoint->refresh()->name)->toBe('Otro nombre')
        ->and($endpoint->url)->toBe('https://otro.example.test/hooks')
        ->and($endpoint->subscribed_events)->toBe(['auth.api_token.issued'])
        ->and($endpoint->active)->toBeFalse()
        ->and($endpoint->secret)->toBe($secret);
});

it('rota el secreto y devuelve el nuevo', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    $anterior = $endpoint->secret;

    $nuevo = resolve(WebhookEndpointRotateSecretAction::class)->handle($endpoint);

    expect($nuevo)->not->toBe($anterior)
        ->and($endpoint->refresh()->secret)->toBe($nuevo);
});

it('borra el endpoint y sus entregas en cascada', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->count(3)->create(['endpoint_id' => $endpoint->id]);

    resolve(WebhookEndpointDeleteAction::class)->handle($endpoint);

    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Reintento manual y purga
|--------------------------------------------------------------------------
*/

it('el reintento manual devuelve los intentos a cero y reencola', function (): void {
    // Si no reiniciara el contador, la entrega tendría un único intento antes de
    // volver a agotarse y el arreglo del receptor no habría servido de nada.
    Event::fake([WebhookDeliveryQueued::class]);

    $delivery = WebhookDelivery::factory()->exhausted()->create();

    expect(resolve(WebhookDeliveryRetryAction::class)->handle($delivery))->toBeTrue();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->response_status)->toBeNull()
        // El último error se conserva: la pantalla sigue contando qué pasó.
        ->and($delivery->last_error)->not->toBeNull();

    Event::assertDispatchedTimes(WebhookDeliveryQueued::class, 1);
});

it('el reintento manual no toca una entrega ya entregada', function (): void {
    Event::fake([WebhookDeliveryQueued::class]);

    $delivery = WebhookDelivery::factory()->delivered()->create();

    expect(resolve(WebhookDeliveryRetryAction::class)->handle($delivery))->toBeFalse()
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);

    Event::assertNotDispatched(WebhookDeliveryQueued::class);
});

it('la purga se lleva las cerradas viejas y respeta las que siguen en juego', function (): void {
    WebhookDelivery::factory()->delivered(daysAgo: 40)->create();
    WebhookDelivery::factory()->exhausted(daysAgo: 40)->create();
    $reciente = WebhookDelivery::factory()->delivered(daysAgo: 5)->create();
    $enJuego = WebhookDelivery::factory()->retryingIn(600)->create();

    $borradas = resolve(WebhookDeliveryPruneAction::class)->handle(30);

    expect($borradas)->toBe(2)
        ->and(WebhookDelivery::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$reciente->id, $enJuego->id]);
});

it('la purga en simulacro cuenta lo mismo y no borra', function (): void {
    WebhookDelivery::factory()->count(2)->delivered(daysAgo: 40)->create();

    expect(resolve(WebhookDeliveryPruneAction::class)->handle(30, dryRun: true))->toBe(2)
        ->and(WebhookDelivery::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| SSRF: las Actions repiten la comprobación de la URL
|--------------------------------------------------------------------------
|
| El formulario ya la hace, pero una Action tiene que servir igual desde un
| comando o un seeder, y ahí no hay validador. Es la misma razón por la que
| `Platform\Actions\SettingUpdateAction` repite la validación de sus claves.
|
*/

it('el alta rechaza una URL que apunta a la red interna', function (): void {
    Config::set('kore-webhooks.allow_private_networks', false);

    expect(fn (): mixed => resolve(WebhookEndpointCreateAction::class)->handle(
        new WebhookEndpointData(
            name: 'Metadatos',
            url: 'https://169.254.169.254/latest/meta-data/',
            events: ['*'],
        ),
    ))->toThrow(InvalidArgumentException::class);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('la edición tampoco deja reapuntar un endpoint a la red interna', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    Config::set('kore-webhooks.allow_private_networks', false);

    expect(fn (): mixed => resolve(WebhookEndpointUpdateAction::class)->handle(
        $endpoint,
        new WebhookEndpointData(
            name: $endpoint->name,
            url: 'https://127.0.0.1:9200/_search',
            events: ['*'],
        ),
    ))->toThrow(InvalidArgumentException::class);

    expect($endpoint->refresh()->url)->not->toBe('https://127.0.0.1:9200/_search');
});

it('el alta rechaza una URL sin https cuando el entorno lo exige', function (): void {
    // Sin `allow_private_networks` de por medio: lo que falla es la otra mitad
    // de `EndpointUrl::guard()`, y falla antes de resolver nada.
    expect(fn (): mixed => resolve(WebhookEndpointCreateAction::class)->handle(
        new WebhookEndpointData(
            name: 'Sin TLS',
            url: 'http://hub.example.test/hooks',
            events: ['*'],
        ),
    ))->toThrow(InvalidArgumentException::class);
});
