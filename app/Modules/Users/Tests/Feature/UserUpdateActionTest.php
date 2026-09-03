<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Actions\UserUpdateAction;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Events\UserUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);

    $this->action = resolve(UserUpdateAction::class);

    $this->user = User::factory()->create(['name' => 'Original']);
    $this->user->syncRoles([Role::USER]);
    $this->user->syncPermissions(['dashboard.view']);
});

it('updates data, role and permissions', function (): void {
    $updated = $this->action->handle($this->user, new UserData(
        name: 'Renombrada',
        email: 'nueva@example.com',
        password: null,
        role: Role::ADMIN,
        permissions: ['users.view'],
    ));

    expect($updated->fresh()->name)->toBe('Renombrada')
        ->and($updated->fresh()->email)->toBe('nueva@example.com')
        ->and($updated->hasRole(Role::ADMIN))->toBeTrue()
        ->and($updated->hasRole(Role::USER))->toBeFalse()
        ->and($updated->getDirectPermissions()->pluck('name')->all())->toBe(['users.view']);
});

it('leaves the password untouched when it comes empty', function (): void {
    $original = (string) $this->user->password;

    foreach ([null, ''] as $password) {
        $this->action->handle($this->user, new UserData(
            name: 'Sin password',
            email: $this->user->email,
            password: $password,
            role: Role::USER,
            permissions: [],
        ));

        expect($this->user->fresh()->password)->toBe($original);
    }
});

it('changes the password when one is provided', function (): void {
    $this->action->handle($this->user, new UserData(
        name: $this->user->name,
        email: $this->user->email,
        password: 'OtraPass123!',
        role: Role::USER,
        permissions: [],
    ));

    expect(Hash::check('OtraPass123!', (string) $this->user->fresh()->password))->toBeTrue();
});

it('dispatches UserUpdated', function (): void {
    Event::fake([UserUpdated::class]);

    $this->action->handle($this->user, new UserData(
        name: 'Otra',
        email: $this->user->email,
        password: null,
        role: Role::USER,
        permissions: [],
    ));

    Event::assertDispatched(UserUpdated::class, fn (UserUpdated $event): bool => $event->user->is($this->user));
});
