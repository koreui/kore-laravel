<?php

declare(strict_types=1);

use App\Core\Enums\AccountStatus;
use App\Core\Enums\SystemRole;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Events\AccountActivated;
use App\Modules\Users\Actions\UserAccountStatusChangeAction;
use App\Modules\Users\Http\Livewire\AccountStatusPanel;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Panel de estado de cuenta (módulo Users)
|--------------------------------------------------------------------------
|
| La Action no depende del toggle —es un caso de uso— y por eso sus tests corren
| tal cual. El componente sí: su alias sólo se registra con `AUTH_INVITATIONS`
| encendido, así que esos casos arrancan la aplicación con `withEnvironment()`.
|
*/

/** Alguien que puede editar usuarios. */
function accountStatusEditor(): User
{
    $user = User::factory()->create();
    $user->syncRoles([SystemRole::User->value]);
    $user->syncPermissions(['users.view', 'users.edit']);

    return $user;
}

beforeEach(function (): void {
    (new ModulesSeeder)->run();
});

it('suspends and reactivates an account, keeping the first activation date', function (): void {
    $editor = accountStatusEditor();
    $target = User::factory()->create();
    $activatedAt = $target->activated_at;

    $action = resolve(UserAccountStatusChangeAction::class);

    $action->handle($target, AccountStatus::Suspended, $editor);
    expect($target->fresh()?->accountStatus())->toBe(AccountStatus::Suspended);

    $action->handle($target, AccountStatus::Active, $editor);

    expect($target->fresh()?->accountStatus())->toBe(AccountStatus::Active)
        ->and($target->fresh()?->activated_at?->toIso8601String())->toBe($activatedAt?->toIso8601String());
});

it('stamps activated_at the first time an account is activated', function (): void {
    $editor = accountStatusEditor();
    $target = User::factory()->pending()->create();

    expect($target->activated_at)->toBeNull();

    resolve(UserAccountStatusChangeAction::class)->handle($target, AccountStatus::Active, $editor);

    expect($target->fresh()?->activated_at)->not->toBeNull();
});

it('dispatches AccountActivated only when the status really changes', function (): void {
    Event::fake([AccountActivated::class]);

    $editor = accountStatusEditor();
    $target = User::factory()->pending()->create();
    $action = resolve(UserAccountStatusChangeAction::class);

    $action->handle($target, AccountStatus::Active, $editor);
    Event::assertDispatchedTimes(AccountActivated::class, 1);

    $action->handle($target->fresh() ?? $target, AccountStatus::Active, $editor);
    Event::assertDispatchedTimes(AccountActivated::class, 1);
});

it('refuses to change your own status, superadmin included', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->syncRoles([SystemRole::Superadmin->value]);

    expect(fn (): User => resolve(UserAccountStatusChangeAction::class)
        ->handle($superadmin, AccountStatus::Suspended, $superadmin))
        ->toThrow(ConflictException::class);

    expect($superadmin->fresh()?->accountStatus())->toBe(AccountStatus::Active);
});

it('records the change in the activity log', function (): void {
    $editor = accountStatusEditor();
    $target = User::factory()->create();

    resolve(UserAccountStatusChangeAction::class)->handle($target, AccountStatus::Suspended, $editor);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->subject_id)->toBe($target->id)
        ->and($activity?->attribute_changes->get('attributes', [])['account_status'] ?? null)
        ->toBe(AccountStatus::Suspended->value);
});

it('lets an editor suspend someone from the panel', function (): void {
    $editor = accountStatusEditor();
    $target = User::factory()->create();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($editor, $target): void {
        Livewire::actingAs($editor)
            ->test(AccountStatusPanel::class, ['user' => $target])
            ->assertSee('Activa')
            ->call('suspend')
            ->assertHasNoErrors();

        expect($target->fresh()?->accountStatus())->toBe(AccountStatus::Suspended);
    });
});

it('does not let someone without users.edit touch the panel (R23)', function (): void {
    $stranger = User::factory()->create();
    $stranger->syncRoles([SystemRole::User->value]);
    $target = User::factory()->create();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($stranger, $target): void {
        Livewire::actingAs($stranger)
            ->test(AccountStatusPanel::class, ['user' => $target])
            ->call('suspend')
            ->assertForbidden();

        expect($target->fresh()?->accountStatus())->toBe(AccountStatus::Active);
    });
});

it('does not let a non superadmin suspend a superadmin', function (): void {
    $editor = accountStatusEditor();
    $superadmin = User::factory()->create();
    $superadmin->syncRoles([SystemRole::Superadmin->value]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($editor, $superadmin): void {
        Livewire::actingAs($editor)
            ->test(AccountStatusPanel::class, ['user' => $superadmin])
            ->call('suspend')
            ->assertForbidden();

        expect($superadmin->fresh()?->accountStatus())->toBe(AccountStatus::Active);
    });
});

it('hides the lever on your own account', function (): void {
    $editor = accountStatusEditor();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($editor): void {
        Livewire::actingAs($editor)
            ->test(AccountStatusPanel::class, ['user' => $editor])
            ->assertSet('canChange', false)
            ->assertSee('No puedes cambiar el estado de tu propia cuenta.');
    });
});

it('shows the panel on the edit screen only with the toggle on', function (): void {
    $editor = accountStatusEditor();
    $target = User::factory()->create();

    $this->actingAs($editor)->get(route('users.edit', $target))
        ->assertOk()
        ->assertDontSee('Estado de la cuenta');

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($editor, $target): void {
        $this->actingAs($editor)->get(route('users.edit', $target))
            ->assertOk()
            ->assertSee('Estado de la cuenta');
    });
});
