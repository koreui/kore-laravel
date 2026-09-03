<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Http\Livewire\FormComponent;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Escalada de privilegios por el formulario de usuarios.
 *
 * Antes de la v1.1, cualquiera con `users.create` + `users.edit` podía crear
 * una cuenta con CUALQUIER rol y CUALQUIER permiso del sistema —incluidos los
 * que él mismo no tenía— y entrar con ella. `GrantablePermission` y
 * `GrantableRole` cierran esa puerta desde `UserForm::rules()`.
 */
beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);

    // Editor: puede gestionar usuarios, pero no ve roles ni tiene el resto de
    // permisos del sistema.
    $this->editor = User::factory()->create();
    $this->editor->syncRoles([Role::USER]);
    $this->editor->syncPermissions(['users.view', 'users.create', 'users.edit']);

    $this->admin = User::factory()->create();
    $this->admin->syncRoles([Role::ADMIN]);
    $this->admin->syncPermissions(Permission::all());

    $this->superadmin = User::factory()->create();
    $this->superadmin->syncRoles([Role::SUPERADMIN]);
});

function fillForm(mixed $component, array $overrides = []): mixed
{
    $values = array_merge([
        'form.name' => 'Nueva cuenta',
        'form.email' => 'nueva@example.com',
        'form.password' => 'StrongPass123!',
        'form.password_confirmation' => 'StrongPass123!',
        'form.role' => Role::USER,
        'form.permissions' => [],
    ], $overrides);

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

it('blocks granting a permission the actor does not have', function (): void {
    fillForm(
        Livewire::actingAs($this->editor)->test(FormComponent::class),
        ['form.permissions' => ['roles.view']],
    )
        ->call('save')
        ->assertHasErrors(['form.permissions.0']);

    expect(User::where('email', '=', 'nueva@example.com')->exists())->toBeFalse();
});

it('names the offending permission in the message', function (): void {
    $component = fillForm(
        Livewire::actingAs($this->editor)->test(FormComponent::class),
        ['form.permissions' => ['users.view', 'roles.delete']],
    )->call('save');

    $component->assertHasErrors(['form.permissions.1']);

    expect($component->errors()->first('form.permissions.1'))->toContain('roles.delete');
});

it('lets the actor grant permissions it does have', function (): void {
    fillForm(
        Livewire::actingAs($this->editor)->test(FormComponent::class),
        ['form.permissions' => ['users.view', 'users.create']],
    )
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect(User::where('email', '=', 'nueva@example.com')->firstOrFail()
        ->getDirectPermissions()->pluck('name')->all())
        ->toBe(['users.view', 'users.create']);
});

it('blocks assigning a role whose permissions the actor does not have', function (): void {
    fillForm(
        Livewire::actingAs($this->editor)->test(FormComponent::class),
        ['form.role' => Role::ADMIN],
    )
        ->call('save')
        ->assertHasErrors(['form.role']);

    expect(User::where('email', '=', 'nueva@example.com')->exists())->toBeFalse();
});

it('lets the actor assign a role it fully covers', function (): void {
    // El editor tiene el rol Usuario (dashboard.view), así que puede asignarlo.
    fillForm(Livewire::actingAs($this->editor)->test(FormComponent::class))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect(User::where('email', '=', 'nueva@example.com')->firstOrFail()->hasRole(Role::USER))->toBeTrue();
});

it('lets an admin with every permission assign Administrador', function (): void {
    fillForm(
        Livewire::actingAs($this->admin)->test(FormComponent::class),
        ['form.role' => Role::ADMIN, 'form.permissions' => ['roles.view']],
    )
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect(User::where('email', '=', 'nueva@example.com')->firstOrFail()->hasRole(Role::ADMIN))->toBeTrue();
});

it('lets the superadmin do anything', function (): void {
    fillForm(
        Livewire::actingAs($this->superadmin)->test(FormComponent::class),
        ['form.role' => Role::ADMIN, 'form.permissions' => ['roles.delete']],
    )
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect(User::where('email', '=', 'nueva@example.com')->firstOrFail()->hasRole(Role::ADMIN))->toBeTrue();
});

it('also applies when editing an existing user', function (): void {
    $victim = User::factory()->create();
    $victim->syncRoles([Role::USER]);

    Livewire::actingAs($this->editor)
        ->test(FormComponent::class, ['model' => $victim])
        ->set('form.permissions', ['roles.view'])
        ->call('save')
        ->assertHasErrors(['form.permissions.0']);

    expect($victim->fresh()->getDirectPermissions())->toBeEmpty();
});
