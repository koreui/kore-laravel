<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the login page', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Iniciar sesión');
});

it('logs an existing user in with valid credentials', function (): void {
    $user = User::factory()->create([
        'email' => 'demo@example.com',
        'password' => bcrypt('secret-password'),
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect(config('fortify.home'));

    expect(auth()->id())->toBe($user->id);
});

it('rejects invalid credentials', function (): void {
    User::factory()->create([
        'email' => 'demo@example.com',
        'password' => bcrypt('secret-password'),
    ]);

    $this->post(route('login'), [
        'email' => 'demo@example.com',
        'password' => 'wrong',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});

it('logs the user out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect();

    expect(auth()->check())->toBeFalse();
});
