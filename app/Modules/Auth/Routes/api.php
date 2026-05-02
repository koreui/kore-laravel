<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Module — API routes (Sanctum)
|--------------------------------------------------------------------------
|
| Solo se cargan cuando API_ENABLED=true (ver AuthModuleServiceProvider).
|
*/

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api')
    ->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user())->name('api.user');
    });
