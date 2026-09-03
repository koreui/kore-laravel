<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Spatie\Activitylog\Models\Activity;

it('logs the creation of a user', function (): void {
    $user = User::create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'StrongPass123!',
    ]);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('created')
        ->and($activity->subject_id)->toBe($user->id)
        ->and($activity->subject_type)->toBe($user->getMorphClass());
});

it('logs only the whitelisted attributes and never the password', function (): void {
    $user = User::factory()->create();

    $user->update(['name' => 'Renombrado', 'password' => 'OtraPass123!']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity->description)->toBe('updated')
        ->and(array_keys($activity->attribute_changes->get('attributes', [])))->toBe(['name'])
        ->and($activity->attribute_changes->toArray()['attributes'] ?? [])->not->toHaveKey('password');
});

it('records the authenticated user as causer', function (): void {
    $actor = User::factory()->create();

    $this->actingAs($actor);

    $target = User::factory()->create();
    $target->update(['name' => 'Cambiado por el actor']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity->causer_id)->toBe($actor->id)
        ->and($activity->causer_type)->toBe($actor->getMorphClass())
        ->and($activity->subject_id)->toBe($target->id);
});

it('logs role changes too', function (): void {
    $this->seed(ModulesSeeder::class);

    $role = Role::query()->where('name', Role::USER)->firstOrFail();
    $role->update(['name' => 'Usuario renombrado']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity->description)->toBe('updated')
        ->and(array_keys($activity->attribute_changes->get('attributes', [])))->toBe(['name']);
});

it('does not log a save that changes nothing', function (): void {
    $user = User::factory()->create();
    $before = Activity::query()->count();

    $user->update(['name' => $user->name]);

    expect(Activity::query()->count())->toBe($before);
});
