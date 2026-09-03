<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

it('creates the health result store table', function (): void {
    expect(Schema::hasTable('health_check_result_history_items'))->toBeTrue();
});

it('rejects /health/json without the secret token', function (): void {
    Config::set('health.secret_token', 'un-token-secreto');

    $this->get('/health/json')->assertForbidden();
});

it('serves /health/json with the secret token', function (): void {
    Config::set('health.secret_token', 'un-token-secreto');

    $response = $this->withHeaders(['X-Secret-Token' => 'un-token-secreto'])
        ->get('/health/json?fresh=1')
        ->assertOk();

    expect($response->json())->toHaveKey('checkResults');
});

it('redirects guests away from the /health html page', function (): void {
    $this->get('/health')->assertRedirect(route('login'));
});

it('forbids the html health page to a non superadmin', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    $this->actingAs($admin)->get('/health')->assertForbidden();
});

it('serves the html health page to the superadmin', function (): void {
    $this->seed(ModulesSeeder::class);

    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $this->actingAs($superadmin)->get('/health')->assertOk();
});

it('registers the maintenance commands on the scheduler', function (string $needle): void {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command)
        ->contains(fn (string $command): bool => str_contains($command, $needle));

    expect($scheduled)->toBeTrue();
})->with([
    'health:check',
    'health:schedule-check-heartbeat',
    'queue:prune-batches',
    'queue:prune-failed --hours=168',
    'activitylog:clean',
    'sanctum:prune-expired --hours=24',
]);
