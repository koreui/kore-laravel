<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the register page', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Crear cuenta');
});

it('registers a new user', function (): void {
    $this->post(route('register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ])->assertRedirect();

    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue();
    expect(auth()->check())->toBeTrue();
});

it('rejects duplicate emails', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register'), [
        'name' => 'Whoever',
        'email' => 'taken@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ])->assertSessionHasErrors('email');
});
