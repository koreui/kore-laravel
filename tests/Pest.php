<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Tests dentro de cada módulo (app/Modules/{X}/Tests/{Feature|Unit})
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Unit');

// Evita romper tests cuando Vite no ha compilado assets.
beforeEach(function (): void {
    $this->withoutVite();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something(): void
{
    // Helper functions for tests can go here.
}
