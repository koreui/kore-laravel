<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use App\Core\Enums\ApiErrorCode;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EnsureAccountIsActive
|--------------------------------------------------------------------------
|
| Aquí sí hace falta `withEnvironment()`: el middleware se monta sobre los
| grupos `web` y `api` en el `boot()` del provider, así que un `Config::set()`
| llegaría tarde (ver docs/patterns/test-con-otro-entorno.md).
|
*/

beforeEach(function (): void {
    (new ModulesSeeder)->run();
});

it('lets an active account through', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Active]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    });
});

it('sends a pending account to the waiting screen', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Pending]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.pending'));
    });
});

it('lets a pending account reach the waiting screen and log out', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Pending]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        $this->actingAs($user)->get(route('account.pending'))
            ->assertOk()
            ->assertSee('Tu cuenta está en revisión');

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
    });
});

it('lets a pending account use the livewire endpoint of a free screen', function (): void {
    /*
     * La ruta se llama `default-livewire.update` en Livewire 4 —el prefijo es
     * el nombre del bundle—, así que el patrón `livewire.*` que estaba en
     * `FREE_ROUTES` no la casaba nunca. Sin ella, alguien `pending` con sesión
     * abierta ve la pantalla del magic link y no puede usarla: cada `wire:model`
     * y cada `wire:click` viaja por aquí y se lo lleva a `/account/pending`.
     *
     * Lo que se comprueba es que NO redirige ahí. El payload vacío puede dar un
     * 4xx —Livewire no encuentra componente que actualizar—, y eso está bien:
     * la respuesta la decide Livewire, no el middleware.
     */
    $user = User::factory()->create(['account_status' => AccountStatus::Pending]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        expect(Route::has('default-livewire.update'))->toBeTrue(
            'Livewire cambió el nombre de su ruta de actualización: repasa FREE_ROUTES.'
        );

        $response = $this->actingAs($user)->post(route('default-livewire.update'), []);

        expect($response->headers->get('Location'))->not->toBe(route('account.pending'));
    });
});

it('still blocks a suspended account on any other named route', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Suspended]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        // Una ruta cualquiera que no está en FREE_ROUTES: abrir el endpoint de
        // Livewire no abrió el resto de la aplicación.
        $this->actingAs($user)->get(route('users.index'))->assertRedirect(route('login'));
    });
});

it('closes the session of a suspended account and says why', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Suspended]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        expect(auth()->check())->toBeFalse();
    });
});

it('answers the API with a 403 and its own error code', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Pending]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($user): void {
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/user')
            ->assertForbidden()
            ->assertJsonPath('error.code', ApiErrorCode::AccountNotActive->value)
            ->assertJsonPath('error.message', 'Tu cuenta está en revisión. Te avisaremos en cuanto quede activada.');
    });
});

it('does not block anyone with the toggle off', function (): void {
    $user = User::factory()->create(['account_status' => AccountStatus::Suspended]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
