<?php

declare(strict_types=1);

use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Http\Livewire\Invitations\FormInvitation;
use App\Modules\Auth\Http\Livewire\Invitations\TableInvitations;
use App\Modules\Auth\Models\InvitationCode;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Pantallas de invitaciones (R36: smoke + happy path + autorización)
|--------------------------------------------------------------------------
|
| Todo dentro de `withEnvironment()`: las rutas, los alias de los componentes y
| la policy sólo existen con el toggle encendido.
|
*/

/** Un usuario con el permiso de gestionar invitaciones. */
function invitationManager(): User
{
    $user = User::factory()->create();
    $user->syncRoles([SystemRole::User->value]);
    $user->syncPermissions(['invitations.manage']);

    return $user;
}

beforeEach(function (): void {
    (new ModulesSeeder)->run();
});

it('opens the two screens for someone who can manage invitations', function (): void {
    $manager = invitationManager();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($manager): void {
        $this->actingAs($manager)->get(route('invitations.index'))->assertOk()->assertSee('Invitaciones');
        $this->actingAs($manager)->get(route('invitations.create'))->assertOk()->assertSee('Nueva invitación');
    });
});

it('closes both screens to someone without the permission', function (): void {
    $stranger = User::factory()->create();
    $stranger->syncRoles([SystemRole::User->value]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($stranger): void {
        $this->actingAs($stranger)->get(route('invitations.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('invitations.create'))->assertForbidden();
    });
});

it('creates a code from the form and shows it to copy', function (): void {
    $manager = invitationManager();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($manager): void {
        $component = Livewire::actingAs($manager)
            ->test(FormInvitation::class)
            ->set('form.role', SystemRole::User->value)
            ->set('form.max_uses', 3)
            ->set('form.note', 'Equipo de soporte')
            ->call('save')
            ->assertHasNoErrors();

        $code = $component->get('createdCode');

        expect($code)->toBeString();

        $invitation = InvitationCode::findByCode((string) $code);

        expect($invitation)->not->toBeNull()
            ->and($invitation?->max_uses)->toBe(3)
            ->and($invitation?->created_by)->toBe($manager->id);

        $component->assertSee((string) $code);
    });
});

it('refuses a role that is not assignable', function (): void {
    $manager = invitationManager();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($manager): void {
        Livewire::actingAs($manager)
            ->test(FormInvitation::class)
            ->set('form.role', SystemRole::Superadmin->value)
            ->call('save')
            ->assertHasErrors('form.role');

        expect(InvitationCode::query()->count())->toBe(0);
    });
});

it('stops the form for someone without the permission, also through livewire', function (): void {
    $stranger = User::factory()->create();
    $stranger->syncRoles([SystemRole::User->value]);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($stranger): void {
        Livewire::actingAs($stranger)
            ->test(FormInvitation::class)
            ->assertStatus(403);
    });
});

it('lists the codes and revokes one from the table', function (): void {
    $manager = invitationManager();
    $invitation = InvitationCode::factory()->create(['note' => 'Campaña de octubre']);

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($manager, $invitation): void {
        Livewire::actingAs($manager)
            ->test(TableInvitations::class)
            ->assertSee($invitation->code)
            ->call('revoke', $invitation->id);

        expect($invitation->fresh()?->isUsable())->toBeFalse();
    });
});

it('does not let a stranger revoke a code through /livewire/update (R23)', function (): void {
    $stranger = User::factory()->create();
    $stranger->syncRoles([SystemRole::User->value]);
    $invitation = InvitationCode::factory()->create();

    withEnvironment(['AUTH_INVITATIONS' => 'true'], function () use ($stranger, $invitation): void {
        // La tabla se monta —no autoriza en `mount()`, porque quien decide quién
        // la ve es el middleware de la ruta— y es la llamada la que se corta.
        Livewire::actingAs($stranger)
            ->test(TableInvitations::class)
            ->call('revoke', $invitation->id)
            ->assertForbidden();

        expect($invitation->fresh()?->isUsable())->toBeTrue();
    });
});
