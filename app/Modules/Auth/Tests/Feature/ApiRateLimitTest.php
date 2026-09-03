<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

it('registers the api rate limiter', function (): void {
    expect(RateLimiter::limiter('api'))->not->toBeNull();
});

it('applies throttle:api to the api middleware group', function (): void {
    $group = resolve('router')->getMiddlewareGroups()['api'] ?? [];

    expect($group)->toContain('throttle:api');
});

it('returns rate limit headers on an authenticated api request', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/user')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60)
        ->assertHeader('X-RateLimit-Remaining', 59);
});
