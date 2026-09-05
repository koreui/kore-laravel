<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use App\Core\Enums\ApiErrorCode;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;

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
