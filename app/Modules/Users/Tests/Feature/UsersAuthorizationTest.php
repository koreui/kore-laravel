<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Http\Livewire\FormComponent;
use App\Modules\Users\Http\Livewire\TableUsers;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Las rutas del módulo llevan middleware `permission:*`, pero las peticiones
 * Livewire viajan por /livewire/update, donde ese middleware no corre. Estos
 * tests atacan los componentes directamente, que es como lo haría un cliente
 * malicioso.
 */
beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);

    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole(Role::SUPERADMIN);

    $this->victim = User::factory()->create();
    $this->victim->assignRole(Role::USER);
});

function userWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::USER);
    $user->syncPermissions($permissions);

    return $user;
}

it('blocks confirmDelete for someone who only has users.view', function (): void {
    $viewer = userWithPermissions(['users.view']);

    Livewire::actingAs($viewer)
        ->test(TableUsers::class)
        ->call('confirmDelete', $this->victim->id)
        ->assertForbidden();

    expect(User::whereKey($this->victim->id)->exists())->toBeTrue();
});

it('blocks deleting a superadmin even with users.delete', function (): void {
    $destroyer = userWithPermissions(['users.view', 'users.delete']);

    Livewire::actingAs($destroyer)
        ->test(TableUsers::class)
        ->call('confirmDelete', $this->superadmin->id)
        ->assertForbidden();

    expect(User::whereKey($this->superadmin->id)->exists())->toBeTrue();
});

it('blocks deleting yourself even with users.delete', function (): void {
    $destroyer = userWithPermissions(['users.view', 'users.delete']);

    Livewire::actingAs($destroyer)
        ->test(TableUsers::class)
        ->call('confirmDelete', $destroyer->id)
        ->assertForbidden();

    expect(User::whereKey($destroyer->id)->exists())->toBeTrue();
});

it('blocks a superadmin from deleting themselves', function (): void {
    Livewire::actingAs($this->superadmin)
        ->test(TableUsers::class)
        ->call('confirmDelete', $this->superadmin->id)
        ->assertForbidden();

    expect(User::whereKey($this->superadmin->id)->exists())->toBeTrue();
});

it('lets the superadmin delete a regular user', function (): void {
    Livewire::actingAs($this->superadmin)
        ->test(TableUsers::class)
        ->call('confirmDelete', $this->victim->id)
        ->assertOk();

    expect(User::whereKey($this->victim->id)->exists())->toBeFalse();
});

it('rejects a client-side write to the locked form.id', function (): void {
    $creator = userWithPermissions(['users.view', 'users.create']);
    $originalEmail = $this->victim->email;

    expect(fn (): mixed => Livewire::actingAs($creator)
        ->test(FormComponent::class)
        ->set('form.id', $this->victim->id)
    )->toThrow(CannotUpdateLockedPropertyException::class);

    expect($this->victim->fresh()->email)->toBe($originalEmail);
});

it('does not let users.create escalate into overwriting another user', function (): void {
    $creator = userWithPermissions(['users.view', 'users.create']);
    $originalName = $this->victim->name;

    // Aunque el cliente no pueda tocar form.id, el flujo normal de creación
    // tampoco debe poder pisar a otro usuario: el email duplicado se rechaza.
    Livewire::actingAs($creator)
        ->test(FormComponent::class)
        ->set('form.name', 'Hackeado')
        ->set('form.email', $this->victim->email)
        ->set('form.password', 'StrongPass123!')
        ->set('form.password_confirmation', 'StrongPass123!')
        ->set('form.role', Role::USER)
        ->call('save')
        ->assertHasErrors(['form.email']);

    expect($this->victim->fresh()->name)->toBe($originalName);
});

it('blocks mounting the form in edit mode without users.edit', function (): void {
    $creator = userWithPermissions(['users.view', 'users.create']);

    Livewire::actingAs($creator)
        ->test(FormComponent::class, ['model' => $this->victim])
        ->assertForbidden();
});

it('blocks mounting the form in create mode without users.create', function (): void {
    $viewer = userWithPermissions(['users.view']);

    Livewire::actingAs($viewer)
        ->test(FormComponent::class)
        ->assertForbidden();
});

it('blocks saving an edit without users.edit', function (): void {
    $editor = userWithPermissions(['users.view', 'users.edit']);
    $originalName = $this->victim->name;

    // Monta con permiso, luego se lo quitamos: save() debe re-autorizar.
    $component = Livewire::actingAs($editor)
        ->test(FormComponent::class, ['model' => $this->victim])
        ->set('form.name', 'Renombrado');

    $editor->syncPermissions(['users.view']);
    $editor->forgetCachedPermissions();

    $component->call('save')->assertForbidden();

    expect($this->victim->fresh()->name)->toBe($originalName);
});

it('blocks a non superadmin from editing a superadmin', function (): void {
    $editor = userWithPermissions(['users.view', 'users.edit']);

    Livewire::actingAs($editor)
        ->test(FormComponent::class, ['model' => $this->superadmin])
        ->assertForbidden();
});
