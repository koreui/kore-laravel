<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Http\Livewire\MagicLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\OneTimePasswords\Notifications\OneTimePasswordNotification;

beforeEach(function (): void {
    Notification::fake();
});

it('does not reveal whether the email exists', function (): void {
    Livewire::test(MagicLink::class)
        ->set('email', 'nadie@example.com')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true);

    Notification::assertNothingSent();
});

it('sends the one time password when the email exists', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    Livewire::test(MagicLink::class)
        ->set('email', 'ada@example.com')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true);

    Notification::assertSentTo($user, OneTimePasswordNotification::class);
});

it('throttles the sixth send within the window', function (): void {
    $component = Livewire::test(MagicLink::class)->set('email', 'nadie@example.com');

    foreach (range(1, 5) as $ignored) {
        $component->call('sendCode')->assertHasNoErrors();
    }

    $component->call('sendCode')->assertHasErrors('email');
});

it('throttles per email so a different address still goes through', function (): void {
    $component = Livewire::test(MagicLink::class)->set('email', 'uno@example.com');

    foreach (range(1, 6) as $ignored) {
        $component->call('sendCode');
    }

    $component->assertHasErrors('email');

    Livewire::test(MagicLink::class)
        ->set('email', 'dos@example.com')
        ->call('sendCode')
        ->assertHasNoErrors();
});

it('returns a generic error when authenticating an unknown email', function (): void {
    Livewire::test(MagicLink::class)
        ->set('email', 'nadie@example.com')
        ->set('codeSent', true)
        ->set('code', '123456')
        ->call('authenticate')
        ->assertHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('rejects a wrong code for an existing user', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    Livewire::test(MagicLink::class)
        ->set('email', 'ada@example.com')
        ->set('codeSent', true)
        ->set('code', '000000')
        ->call('authenticate')
        ->assertHasErrors('code');

    expect(auth()->check())->toBeFalse();
});
