<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| `Tests\TestCase::setUp()` ya llama a `withoutVite()`, así que los tests no
| necesitan assets compilados. No lo repitas aquí con un `beforeEach`.
|
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

// tests/Arch no extiende TestCase: los arch tests son estáticos, no bootean la
// aplicación ni tocan la base de datos.
