<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

it('creates the features table', function (): void {
    expect(Schema::hasTable('features'))->toBeTrue();
});

it('publishes the pennant config', function (): void {
    expect(file_exists(config_path('pennant.php')))->toBeTrue()
        ->and(config('pennant.default'))->toBe('database')
        ->and(config('pennant.stores.database.table'))->toBe('features');
});

it('resolves an inline feature', function (): void {
    Feature::define('e2e-demo', fn (): bool => true);

    expect(Feature::active('e2e-demo'))->toBeTrue();
});

it('scopes a feature per user', function (): void {
    $allowed = User::factory()->create(['email' => 'beta@example.com']);
    $denied = User::factory()->create(['email' => 'normal@example.com']);

    Feature::define('beta-panel', fn (User $user): bool => $user->email === 'beta@example.com');

    expect(Feature::for($allowed)->active('beta-panel'))->toBeTrue()
        ->and(Feature::for($denied)->active('beta-panel'))->toBeFalse();
});

it('persists resolved values in the features table', function (): void {
    $user = User::factory()->create();

    Feature::define('persisted-flag', fn (): bool => true);

    Feature::for($user)->active('persisted-flag');

    expect(DB::table('features')->where('name', 'persisted-flag')->exists())->toBeTrue();
});
