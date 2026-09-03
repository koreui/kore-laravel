<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Actions\UserCreateAction;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Events\UserCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);

    $this->action = resolve(UserCreateAction::class);
});

it('creates the user with role, direct permissions and a verified email', function (): void {
    $user = $this->action->handle(new UserData(
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        password: 'StrongPass123!',
        role: Role::USER,
        permissions: ['dashboard.view', 'users.view'],
    ));

    expect($user->exists)->toBeTrue()
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole(Role::USER))->toBeTrue()
        ->and($user->getDirectPermissions()->pluck('name')->all())
        ->toBe(['dashboard.view', 'users.view']);
});

it('hashes the password', function (): void {
    $user = $this->action->handle(new UserData(
        name: 'Ada',
        email: 'ada@example.com',
        password: 'StrongPass123!',
        role: Role::USER,
        permissions: [],
    ));

    expect($user->password)->not->toBe('StrongPass123!')
        ->and(Hash::check('StrongPass123!', (string) $user->password))->toBeTrue();
});

it('dispatches UserCreated', function (): void {
    Event::fake([UserCreated::class]);

    $user = $this->action->handle(new UserData(
        name: 'Ada',
        email: 'ada@example.com',
        password: 'StrongPass123!',
        role: Role::USER,
        permissions: [],
    ));

    Event::assertDispatched(UserCreated::class, fn (UserCreated $event): bool => $event->user->is($user));
});

it('rolls back the user if the role does not exist', function (): void {
    $before = User::query()->count();

    expect(fn (): User => $this->action->handle(new UserData(
        name: 'Ada',
        email: 'ada@example.com',
        password: 'StrongPass123!',
        role: 'rol-inexistente',
        permissions: [],
    )))->toThrow(RoleDoesNotExist::class);

    expect(User::query()->count())->toBe($before);
});
