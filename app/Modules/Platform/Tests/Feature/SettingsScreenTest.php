<?php

declare(strict_types=1);

use App\Core\Contracts\Settings;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Platform\Database\Seeders\PlatformSeeder;
use App\Modules\Platform\Http\Livewire\SettingsForm;
use App\Modules\Platform\Models\Setting;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| `/settings` — ruta, permiso y componente
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
    $this->seed(PlatformSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::ADMIN);
    $this->admin->syncPermissions(Permission::all());

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::USER);
});

it('el seeder deja el permiso settings.manage sembrado', function (): void {
    expect(Permission::query()->where('name', PlatformSeeder::PERMISSION)->exists())->toBeTrue();
});

it('abre la pantalla para quien tiene settings.manage', function (): void {
    $this->actingAs($this->admin)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertSee('Ajustes de la instalación');
});

it('devuelve 403 a quien no lo tiene', function (): void {
    $this->actingAs($this->user)
        ->get(route('settings.edit'))
        ->assertForbidden();
});

it('manda al login a un invitado', function (): void {
    $this->get(route('settings.edit'))->assertRedirect(route('login'));
});

it('guarda los ajustes desde el componente', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(SettingsForm::class)
        ->set('form.values.organization_name', 'Notaría 42')
        ->set('form.values.organization_email', 'contacto@notaria42.test')
        ->call('save')
        ->assertRedirect(route('settings.edit'));

    expect(Setting::query()->where('key', 'organization.name')->value('value'))->toBe('Notaría 42')
        ->and(Setting::query()->where('key', 'organization.name')->value('changed_by'))->toBe($this->admin->id);
});

it('valida contra el tipo declarado en kore-settings.editable', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(SettingsForm::class)
        ->set('form.values.organization_name', '')
        ->set('form.values.organization_email', 'esto-no-es-un-correo')
        ->call('save')
        ->assertHasErrors([
            'form.values.organization_name' => 'required',
            'form.values.organization_email' => 'email',
        ]);

    expect(Setting::query()->count())->toBe(0);
});

it('guarda una cadena vacía opcional como null y no como texto en blanco', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(SettingsForm::class)
        ->set('form.values.organization_name', 'Notaría 42')
        ->set('form.values.organization_phone', '')
        ->call('save');

    expect(Setting::query()->where('key', 'organization.phone')->value('value'))->toBeNull();
});

it('restore devuelve un ajuste a su defecto', function (): void {
    $this->actingAs($this->admin);

    resolve(Settings::class)->set('organization.name', 'Notaría 42', $this->admin->id);

    Livewire::test(SettingsForm::class)
        ->call('restore', 'organization_name')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'organization.name')->exists())->toBeFalse();
});

it('restore con un slug inventado es un 404 y no toca nada', function (): void {
    // /livewire/update acepta cualquier argumento: la lista blanca se comprueba
    // en el componente, no en la vista.
    $this->actingAs($this->admin);

    Livewire::test(SettingsForm::class)
        ->call('restore', 'organization_inventada')
        ->assertStatus(404);
});

it('el componente no monta para quien no tiene el permiso', function (): void {
    $this->actingAs($this->user);

    Livewire::test(SettingsForm::class)->assertForbidden();
});

it('el componente niega guardar cuando el permiso se retira después de montar', function (): void {
    /*
     * R23: la llamada viaja por /livewire/update, donde el `permission:` de la
     * ruta no corre y el `mount()` ya pasó. Sin el authorize() dentro de
     * `save()`, quien tuviera una pestaña abierta seguiría guardando después de
     * que le quitaran el permiso.
     */
    $this->actingAs($this->admin);

    $component = Livewire::test(SettingsForm::class)
        ->set('form.values.organization_name', 'Secuestrada');

    $this->admin->syncPermissions([]);
    $this->admin->syncRoles([]);

    $component->call('save')->assertForbidden();

    expect(Setting::query()->count())->toBe(0);
});

it('el nombre de la organización se pinta en el layout', function (): void {
    // El View Composer de PlatformModuleServiceProvider: si no se registrara,
    // `$organization` llegaría sin definir y el layout reventaría.
    resolve(Settings::class)->set('organization.name', 'Notaría 42', $this->admin->id);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Notaría 42');
});

it('el enlace de ajustes sólo aparece para quien puede administrarlos', function (): void {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('settings.edit'));

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('settings.edit'));
});
