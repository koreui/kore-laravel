<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Http\Livewire\FormComponent;
use App\Modules\Users\Http\Livewire\TableUsers;
use App\Modules\Users\Policies\UserPolicy;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::ADMIN);
    $this->admin->syncPermissions(Permission::all());

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::USER);
});

it('lists users for someone with users.view permission', function (): void {
    $this->actingAs($this->admin)
        ->get(route('users.index'))
        ->assertOk();
});

it('blocks listing for users without permission', function (): void {
    $this->actingAs($this->user)
        ->get(route('users.index'))
        ->assertForbidden();
});

it('shows the create form for admins', function (): void {
    $this->actingAs($this->admin)
        ->get(route('users.create'))
        ->assertOk();
});

it('creates a user via the Livewire form', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(FormComponent::class)
        ->set('form.name', 'Ada Lovelace')
        ->set('form.email', 'ada@example.com')
        ->set('form.password', 'StrongPass123!')
        ->set('form.password_confirmation', 'StrongPass123!')
        ->set('form.role', Role::USER)
        ->set('form.permissions', ['dashboard.view'])
        ->call('save')
        ->assertRedirect(route('users.index'));

    $created = User::where('email', 'ada@example.com')->firstOrFail();
    expect($created->hasRole(Role::USER))->toBeTrue();
    expect($created->getDirectPermissions()->pluck('name')->all())->toBe(['dashboard.view']);
});

it('rejects duplicate emails', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(FormComponent::class)
        ->set('form.name', 'Otro')
        ->set('form.email', $this->user->email)
        ->set('form.password', 'StrongPass123!')
        ->set('form.password_confirmation', 'StrongPass123!')
        ->set('form.role', Role::USER)
        ->call('save')
        ->assertHasErrors(['form.email']);
});

it('updates an existing user without password change', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(FormComponent::class, ['model' => $this->user])
        ->set('form.name', 'Renamed')
        ->call('save')
        ->assertRedirect(route('users.index'));

    expect($this->user->fresh()->name)->toBe('Renamed');
});

it('hides superadmin users from the table query', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $this->actingAs($this->admin);

    $rows = Livewire::test(TableUsers::class)
        ->instance()
        ->query()
        ->get();

    expect($rows->pluck('id')->all())->not->toContain($superadmin->id);
});

it('UserForm validates role is in assignableNames', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(FormComponent::class)
        ->set('form.name', 'X')
        ->set('form.email', 'x@example.com')
        ->set('form.password', 'StrongPass123!')
        ->set('form.password_confirmation', 'StrongPass123!')
        ->set('form.role', 'rol-inexistente')
        ->call('save')
        ->assertHasErrors(['form.role']);
});

it('UserPolicy blocks deleting yourself', function (): void {
    $policy = new UserPolicy;

    expect($policy->delete($this->admin, $this->admin))->toBeFalse();
});

it('UserPolicy blocks deleting superadmin', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $policy = new UserPolicy;

    expect($policy->delete($this->admin, $superadmin))->toBeFalse();
});
