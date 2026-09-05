<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Http\Livewire\FormComponent;
use App\Modules\Webhooks\Http\Livewire\ShowEndpoint;
use App\Modules\Webhooks\Http\Livewire\TableEndpoints;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El camino feliz de las tres pantallas
|--------------------------------------------------------------------------
|
| La autorización tiene su propio archivo (`WebhooksAuthorizationTest`); aquí se
| prueba lo que la pantalla hace cuando sí se puede.
|
*/

/**
 * Corre el callback con el módulo encendido y un actor que puede administrar.
 */
function withWebhooksAdmin(Closure $callback): void
{
    withEnvironment(['WEBHOOKS_ENABLED' => 'true'], function () use ($callback): void {
        test()->seed(ModulesSeeder::class);

        $user = User::factory()->create();
        $user->syncPermissions(['webhooks.manage']);

        test()->actingAs($user);

        // Con el toggle encendido, `DispatchWebhookDelivery` está registrado y
        // la cola de la suite es `sync`: cualquier entrega encolada saldría de
        // verdad a Internet. El cepo lo impide y hace ruidoso el descuido.
        Http::preventStrayRequests();
        Http::fake();

        // Las URLs de este archivo son `*.example.test`, un TLD reservado que
        // no resuelve: `Rules\PublicHttpUrl` las rechazaría por eso y estos
        // tests dejarían de probar lo suyo. La regla tiene su propio archivo
        // (`Tests/Unit/PublicHttpUrlTest`), donde se prueba con IPs literales y
        // con `localhost`; aquí se le abre la mano para que el camino feliz de
        // las pantallas no dependa del DNS.
        Config::set('kore-webhooks.allow_private_networks', true);

        $callback($user);
    });
}

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

it('crea el endpoint y deja el secreto en sesión una sola vez', function (): void {
    withWebhooksAdmin(function (User $user): void {
        Livewire::test(FormComponent::class)
            ->set('form.name', 'Panel central')
            ->set('form.url', 'https://hub.example.test/hooks')
            ->set('form.events', [WebhookEndpoint::ALL_EVENTS])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $endpoint = WebhookEndpoint::query()->firstOrFail();

        expect($endpoint->name)->toBe('Panel central')
            ->and($endpoint->created_by)->toBe($user->id)
            // El secreto viaja por sesión y no como propiedad del componente:
            // una propiedad pública se quedaría en el snapshot de cada petición
            // siguiente, es decir en el DOM.
            ->and(session(FormComponent::SECRET_FLASH_KEY))->toBe($endpoint->secret);
    });
});

it('exige nombre, URL y al menos un evento', function (): void {
    withWebhooksAdmin(function (): void {
        Livewire::test(FormComponent::class)
            ->set('form.name', '')
            ->set('form.url', '')
            ->set('form.events', [])
            ->call('save')
            ->assertHasErrors(['form.name', 'form.url', 'form.events']);
    });
});

it('rechaza una URL que no es https fuera de local', function (): void {
    // La firma protege la integridad, no la confidencialidad: por http el
    // payload viaja legible para cualquiera en el camino.
    withWebhooksAdmin(function (): void {
        Livewire::test(FormComponent::class)
            ->set('form.name', 'Inseguro')
            ->set('form.url', 'http://hub.example.test/hooks')
            ->set('form.events', [WebhookEndpoint::ALL_EVENTS])
            ->call('save')
            ->assertHasErrors(['form.url']);
    });
});

it('rechaza un evento que no está en el catálogo', function (): void {
    withWebhooksAdmin(function (): void {
        Config::set('kore-webhooks.events', ['auth.api_token.issued' => 'Token emitido']);

        Livewire::test(FormComponent::class)
            ->set('form.name', 'Raro')
            ->set('form.url', 'https://hub.example.test/hooks')
            ->set('form.events', ['orders.inventado'])
            ->call('save')
            ->assertHasErrors(['form.events.*']);
    });
});

it('edita sin tocar el secreto', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $secret = $endpoint->secret;

        Livewire::test(FormComponent::class, ['model' => $endpoint])
            ->assertSet('form.name', $endpoint->name)
            ->set('form.name', 'Renombrado')
            ->call('save')
            ->assertHasNoErrors();

        expect($endpoint->refresh()->name)->toBe('Renombrado')
            ->and($endpoint->secret)->toBe($secret)
            // Editar no enseña ningún secreto: sólo el alta y la rotación.
            ->and(session(FormComponent::SECRET_FLASH_KEY))->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| El listado
|--------------------------------------------------------------------------
*/

it('la tabla lista los endpoints y cuenta lo que tienen en cola', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create(['name' => 'Integración viva']);
        WebhookDelivery::factory()->count(2)->create(['endpoint_id' => $endpoint->id]);
        WebhookDelivery::factory()->delivered()->create(['endpoint_id' => $endpoint->id]);

        Livewire::test(TableEndpoints::class)
            ->assertOk()
            ->assertSee('Integración viva')
            // Sólo las abiertas: la entregada no está en cola.
            ->assertSee('2');
    });
});

it('la tabla borra el endpoint que autoriza', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();

        Livewire::test(TableEndpoints::class)
            ->call('deleteAuthorized', $endpoint->id)
            ->assertDispatched('webhooks-updated');

        expect(WebhookEndpoint::query()->count())->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| El detalle
|--------------------------------------------------------------------------
*/

it('el detalle lista las entregas y su resumen por estado', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();
        WebhookDelivery::factory()->delivered()->create(['endpoint_id' => $endpoint->id]);
        WebhookDelivery::factory()->exhausted()->create(['endpoint_id' => $endpoint->id]);

        Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint])
            ->assertOk()
            ->assertSee('auth.api_token.issued')
            ->assertSee('Entregado')
            ->assertSee('Agotado');
    });
});

it('el filtro de estado acota la lista', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $entregada = WebhookDelivery::factory()->delivered()->create(['endpoint_id' => $endpoint->id]);
        $agotada = WebhookDelivery::factory()->exhausted()->create(['endpoint_id' => $endpoint->id]);

        Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint])
            ->set('status', DeliveryStatus::Exhausted->value)
            ->assertSee($agotada->uuid)
            ->assertDontSee($entregada->uuid);
    });
});

it('el detalle reencola una entrega agotada', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $delivery = WebhookDelivery::factory()->exhausted()->create(['endpoint_id' => $endpoint->id]);

        // Sin el fake del evento, el listener en cola (la suite corre en `sync`)
        // entregaría la fila en el acto y no se vería el estado intermedio, que
        // es justo lo que este test mira.
        Event::fake([WebhookDeliveryQueued::class]);

        Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint])
            ->call('retryDelivery', $delivery->id);

        expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
            ->and($delivery->attempts)->toBe(0);

        Event::assertDispatchedTimes(WebhookDeliveryQueued::class, 1);
    });
});

it('el detalle rota el secreto y lo deja en sesión', function (): void {
    withWebhooksAdmin(function (): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $anterior = $endpoint->secret;

        Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint])
            ->call('rotateSecret')
            ->assertRedirect();

        expect($endpoint->refresh()->secret)->not->toBe($anterior)
            ->and(session(FormComponent::SECRET_FLASH_KEY))->toBe($endpoint->secret);
    });
});
