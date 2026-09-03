<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\AuthUserRegisterAction;
use App\Modules\Auth\Data\RegisterData;
use App\Modules\Auth\Fortify\CreateNewUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('registers a user with a hashed password and without verifying the email', function (): void {
    $user = resolve(AuthUserRegisterAction::class)->handle(new RegisterData(
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        password: 'StrongPass123!',
    ));

    expect($user->exists)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('StrongPass123!', (string) $user->password))->toBeTrue();
});

it('is what the Fortify adapter delegates to', function (): void {
    $user = resolve(CreateNewUser::class)->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and(User::where('email', '=', 'ada@example.com')->exists())->toBeTrue();
});

it('still validates the Fortify input before delegating', function (): void {
    expect(fn (): User => resolve(CreateNewUser::class)->create([
        'name' => '',
        'email' => 'no-es-un-email',
        'password' => 'x',
    ]))->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(0);
});
