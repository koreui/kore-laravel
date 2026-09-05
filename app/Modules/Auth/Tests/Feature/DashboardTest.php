<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Models\Module;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

it('renders the dashboard with the three real counters', function (): void {
    // La cifra de abajo (5) es la de los módulos que siembra ModulesSeeder:
    // dashboard, users, roles, invitations y settings. Sube cuando entra un módulo nuevo.
    $user = User::factory()->create();

    // Un módulo inactivo NO cuenta: la cifra es de módulos activos.
    Module::factory()->create(['active' => false]);

    $expected = [
        User::query()->count(),
        Permission::query()->count(),
        Module::query()->where('active', '=', true)->count(),
    ];

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Hola, '.$user->name)
        ->assertSee('Usuarios totales')
        ->assertSee('Permisos del sistema')
        ->assertSee('Módulos activos', escape: false);

    foreach ($expected as $count) {
        $response->assertSee('>'.$count.'</div>', escape: false);
    }

    // Los seis que siembra ModulesSeeder: dashboard, users, roles,
    // invitations, settings y webhooks. El inactivo de arriba no entra.
    expect($expected[2])->toBe(6);
});

it('exposes the counters as DTOs, not Eloquent', function (): void {
    $user = User::factory()->create();

    $stats = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->stats();

    expect($stats)->toHaveCount(3)
        ->and($stats[0]->label)->toBe('Usuarios totales')
        ->and($stats[0]->value)->toBe(User::query()->count())
        ->and($stats[0]->icon)->toBe('users');
});

it('keeps the page title and the users shortcut behind the permission', function (): void {
    $withPermission = User::factory()->create();
    $withPermission->givePermissionTo('users.view');

    $this->actingAs($withPermission)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<h1 class="text-2xl font-bold tracking-tight">Dashboard</h1>', escape: false)
        ->assertSee('Gestionar usuarios');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Gestionar usuarios');
});
