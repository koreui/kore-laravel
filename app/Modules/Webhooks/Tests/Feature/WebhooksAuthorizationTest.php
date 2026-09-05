<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Http\Livewire\FormComponent;
use App\Modules\Webhooks\Http\Livewire\ShowEndpoint;
use App\Modules\Webhooks\Http\Livewire\TableEndpoints;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Policies\WebhookEndpointPolicy;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Autorización: la ruta y, sobre todo, el componente
|--------------------------------------------------------------------------
|
| R23 · el `permission:webhooks.manage` de las rutas NO corre en
| `/livewire/update`. Por eso aquí no basta con comprobar los 403 de las
| pantallas: cada método público que escribe se prueba también desde el
| componente, que es por donde entra de verdad una petición de Livewire.
|
| Todo esto vive detrás del toggle, así que cada test arranca la aplicación con
| `withEnvironment()` (tests/Pest.php).
|
*/

/**
 * Corre el callback con el módulo encendido, los permisos sembrados y una
 * sesión iniciada.
 *
 * @param array<int, string> $permissions
 */
function withWebhooksUser(array $permissions, Closure $callback): void
{
    withEnvironment(['WEBHOOKS_ENABLED' => 'true'], function () use ($permissions, $callback): void {
        test()->seed(ModulesSeeder::class);

        $user = User::factory()->create();
        $user->syncPermissions($permissions);

        test()->actingAs($user);

        $callback($user);
    });
}

/**
 * Le quita el permiso al actor y olvida la caché de spatie, para que la
 * siguiente comprobación lo note.
 */
function revokeWebhooksPermission(User $user): void
{
    $user->syncPermissions([]);
    $user->unsetRelation('permissions');
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
}

/*
|--------------------------------------------------------------------------
| Las pantallas
|--------------------------------------------------------------------------
*/

it('deja entrar al listado a quien tiene webhooks.manage', function (): void {
    withWebhooksUser(['webhooks.manage'], function (): void {
        test()->get('/webhooks')->assertOk();
        test()->get('/webhooks/create')->assertOk();
    });
});

it('devuelve 403 a quien no tiene el permiso', function (): void {
    withWebhooksUser([], function (): void {
        test()->get('/webhooks')->assertForbidden();
        test()->get('/webhooks/create')->assertForbidden();
    });
});

it('el detalle y la edición enrutan por uuid, no por id', function (): void {
    withWebhooksUser(['webhooks.manage'], function (): void {
        $endpoint = WebhookEndpoint::factory()->create();

        test()->get("/webhooks/{$endpoint->uuid}")->assertOk();
        test()->get("/webhooks/{$endpoint->uuid}/edit")->assertOk();

        // El id entero no abre nada: es lo que evita que probar el 8 sea gratis.
        test()->get("/webhooks/{$endpoint->id}")->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| La policy
|--------------------------------------------------------------------------
*/

it('la policy concede sólo con webhooks.manage', function (): void {
    withWebhooksUser(['webhooks.manage'], function (User $user): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $policy = new WebhookEndpointPolicy;

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user, $endpoint))->toBeTrue()
            ->and($policy->create($user))->toBeTrue()
            ->and($policy->update($user, $endpoint))->toBeTrue()
            ->and($policy->delete($user, $endpoint))->toBeTrue();
    });
});

it('la policy niega sin el permiso', function (): void {
    withWebhooksUser([], function (User $user): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $policy = new WebhookEndpointPolicy;

        expect($policy->viewAny($user))->toBeFalse()
            ->and($policy->view($user, $endpoint))->toBeFalse()
            ->and($policy->create($user))->toBeFalse()
            ->and($policy->update($user, $endpoint))->toBeFalse()
            ->and($policy->delete($user, $endpoint))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| Los componentes: la puerta que el middleware de la ruta no cubre
|--------------------------------------------------------------------------
*/

it('el formulario de alta no monta para quien no puede crear', function (): void {
    withWebhooksUser([], function (): void {
        Livewire::test(FormComponent::class)->assertForbidden();
    });
});

it('save() vuelve a autorizar y no se fía de que el mount pasara', function (): void {
    // Es exactamente lo que R23 protege: montar la pantalla y llamar a `save()`
    // son DOS peticiones, y la segunda viaja por `/livewire/update`, donde el
    // `permission:webhooks.manage` de la ruta no corre. Aquí se le quita el
    // permiso entre una y otra.
    withWebhooksUser(['webhooks.manage'], function (User $user): void {
        $component = Livewire::test(FormComponent::class)
            ->set('form.name', 'Intruso')
            ->set('form.url', 'https://example.test/hooks')
            ->set('form.events', ['*']);

        revokeWebhooksPermission($user);

        $component->call('save')->assertForbidden();

        expect(WebhookEndpoint::query()->count())->toBe(0);
    });
});

it('la tabla no deja borrar a quien no puede', function (): void {
    withWebhooksUser([], function (): void {
        $endpoint = WebhookEndpoint::factory()->create();

        Livewire::test(TableEndpoints::class)
            ->call('deleteAuthorized', $endpoint->id)
            ->assertForbidden();

        expect(WebhookEndpoint::query()->count())->toBe(1);
    });
});

it('el detalle no monta para quien no puede verlo', function (): void {
    withWebhooksUser([], function (): void {
        $endpoint = WebhookEndpoint::factory()->create();

        Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint])->assertForbidden();
    });
});

it('rotateSecret() vuelve a autorizar', function (): void {
    withWebhooksUser(['webhooks.manage'], function (User $user): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $secret = $endpoint->secret;

        $component = Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint]);

        revokeWebhooksPermission($user);

        $component->call('rotateSecret')->assertForbidden();

        expect($endpoint->refresh()->secret)->toBe($secret);
    });
});

it('retryDelivery() vuelve a autorizar', function (): void {
    withWebhooksUser(['webhooks.manage'], function (User $user): void {
        $endpoint = WebhookEndpoint::factory()->create();
        $delivery = WebhookDelivery::factory()->exhausted()->create(['endpoint_id' => $endpoint->id]);

        $component = Livewire::test(ShowEndpoint::class, ['endpoint' => $endpoint]);

        revokeWebhooksPermission($user);

        $component->call('retryDelivery', $delivery->id)->assertForbidden();

        expect($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted);
    });
});

it('retryDelivery() no toca una entrega de otro endpoint', function (): void {
    // El id llega del cliente: sin el corte por `endpoint_id`, cualquiera con
    // acceso a una integración podría reencolar las de otra.
    withWebhooksUser(['webhooks.manage'], function (): void {
        $mio = WebhookEndpoint::factory()->create();
        $ajeno = WebhookDelivery::factory()->exhausted()->create();

        Livewire::test(ShowEndpoint::class, ['endpoint' => $mio])
            ->call('retryDelivery', $ajeno->id);

        expect($ajeno->refresh()->status)->toBe(DeliveryStatus::Exhausted);
    });
});
