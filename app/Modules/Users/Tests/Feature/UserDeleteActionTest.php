<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Users\Actions\UserDeleteAction;
use App\Modules\Users\Events\UserDeleted;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->action = resolve(UserDeleteAction::class);
});

it('deletes the user', function (): void {
    $user = User::factory()->create();

    $this->action->handle($user);

    expect(User::whereKey($user->id)->exists())->toBeFalse();
});

it('dispatches UserDeleted with the model that no longer exists', function (): void {
    Event::fake([UserDeleted::class]);

    $user = User::factory()->create();
    $id = $user->id;

    $this->action->handle($user);

    Event::assertDispatched(UserDeleted::class, fn (UserDeleted $event): bool => $event->user->id === $id);
});
