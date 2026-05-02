<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('users')
    ->as('users.')
    ->controller(UsersController::class)
    ->group(function (): void {
        Route::middleware('permission:users.view')->get('/', 'index')->name('index');
        Route::middleware('permission:users.create')->get('/create', 'create')->name('create');
        Route::middleware('permission:users.edit')->get('/{user}/edit', 'edit')->name('edit');
    });
