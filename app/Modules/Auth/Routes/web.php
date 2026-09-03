<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\SocialiteController;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Http\Livewire\MagicLink;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Module — Web routes
|--------------------------------------------------------------------------
|
| Las rutas estándar (login, register, forgot-password, etc.) las publica
| Fortify automáticamente y renderiza las views configuradas en
| FortifyServiceProvider. Aquí registramos solo lo no cubierto por Fortify.
|
*/

Route::middleware('web')->group(function (): void {

    if ((bool) config('kore-app.auth.magic_links')) {
        Route::get('/magic-link', MagicLink::class)->name('magic-link.request');
    }

    if ((bool) config('kore-app.auth.social_login')) {
        Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
            ->whereIn('provider', ['google', 'github'])
            ->name('socialite.redirect');

        Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])
            ->whereIn('provider', ['google', 'github'])
            ->name('socialite.callback');
    }
});

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
});
