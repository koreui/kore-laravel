<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

it('defaults PULSE_ENABLED to false', function (): void {
    // El README y CLAUDE.md prometen `false`; el config publicado decía `true`.
    // phpunit.xml ya fuerza el valor en la suite, así que el default real se
    // comprueba sobre el archivo de config publicado.
    expect(file_get_contents(config_path('pulse.php')))
        ->toContain("env('PULSE_ENABLED', false)")
        ->and(config('pulse.enabled'))->toBeFalsy();
});

it('only lets the superadmin through the viewPulse gate', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    expect(Gate::forUser($superadmin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewPulse'))->toBeFalse();
});

it('rejects a regular user on the pulse dashboard', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($admin)
        ->get('/'.config('pulse.path'))
        ->assertForbidden();
});

it('rejects guests on the pulse dashboard', function (): void {
    $this->get('/'.config('pulse.path'))->assertForbidden();
});

it('lets the superadmin into the pulse dashboard', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $this->actingAs($superadmin)
        ->get('/'.config('pulse.path'))
        ->assertOk();
});
