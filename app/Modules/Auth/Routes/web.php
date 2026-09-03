<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\SocialiteController;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Http\Livewire\MagicLink;
use App\Modules\Auth\Http\Livewire\Passkeys;
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

/*
 * Pantalla de gestión de passkeys. Los endpoints de la ceremonia WebAuthn
 * (`/passkeys/login*`, `/user/passkeys*`) los publica Fortify; esto es sólo la
 * UI que los usa, y por eso el nombre es `passkeys.*` y no `passkey.*`.
 *
 * `password.confirm` va aquí a propósito, aunque Fortify ya lo exija en sus
 * rutas de gestión: sin él la confirmación saltaría a mitad del flujo —el
 * usuario abre la pantalla, escribe el nombre, el navegador le pide la huella y
 * sólo entonces el POST se va a `/user/confirm-password`, perdiendo la
 * credencial recién creada—. Con el middleware en la pantalla, la contraseña se
 * confirma antes de empezar.
 */
if ((bool) config('kore-app.auth.passkeys')) {
    Route::middleware(['web', 'auth', 'verified', 'password.confirm'])->group(function (): void {
        Route::get('/user/passkeys', Passkeys::class)->name('passkeys.index');
    });
}
