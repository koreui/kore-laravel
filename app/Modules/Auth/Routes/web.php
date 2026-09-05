<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AccountController;
use App\Modules\Auth\Http\Controllers\InvitationsController;
use App\Modules\Auth\Http\Controllers\SocialiteController;
use App\Modules\Auth\Http\Livewire\Dashboard;
use App\Modules\Auth\Http\Livewire\DevAccountSwitcher;
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
 * Switcher de cuentas de demostración: entrar como cualquiera de las cuentas
 * sembradas para ver la aplicación con sus permisos.
 *
 * Sólo en `local`, y la comprobación va aquí —no dentro de la pantalla— para
 * que fuera de local la ruta **no exista**: `Route::has('dev.switch-account')`
 * es `false` y la URL responde 404, en vez de un 403 que delataría que hay
 * algo detrás. La misma forma que usan `magic-link` y `passkeys` arriba, con
 * el entorno en lugar de un toggle.
 */
if (app()->environment('local')) {
    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::get('/dev/switch-account', DevAccountSwitcher::class)->name('dev.switch-account');
    });
}

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

/*
 * Invitaciones y estado de cuenta (`AUTH_INVITATIONS`).
 *
 * Las tres rutas existen o no existen juntas, y con el toggle apagado ninguna:
 * `/invitations` es un 404 como cualquier URL inventada, no un 403 que delataría
 * que hay algo detrás. Es la misma forma que usan `magic-link` y `passkeys`.
 *
 * `/account/pending` no lleva `verified` a propósito: quien está pendiente de
 * activación puede estar además pendiente de verificar su correo, y encadenar
 * las dos esperas dejaría la pantalla de espera inalcanzable. Tampoco lleva
 * `permission:*`: es la pantalla a la que `EnsureAccountIsActive` manda a la
 * gente, y esa lista de rutas libres la incluye por su nombre.
 */
if ((bool) config('kore-app.auth.invitations')) {
    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::get('/account/pending', [AccountController::class, 'pending'])->name('account.pending');
    });

    Route::middleware(['web', 'auth', 'verified', 'permission:invitations.manage'])
        ->prefix('invitations')
        ->as('invitations.')
        ->controller(InvitationsController::class)
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
        });
}
