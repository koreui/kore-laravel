<?php

declare(strict_types=1);

use App\Modules\Auth\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Toggle AUTH_PASSKEYS
|--------------------------------------------------------------------------
|
| Mismo contrato que `TwoFactorToggleTest`: encendido registra la feature y sus
| rutas, apagado no deja nada. Ver R10, R11 y R12 en
| docs/architecture/rules.md.
|
| Las seis rutas de la ceremonia las publica Fortify (`passkey.*`); la pantalla
| de gestión es del módulo (`passkeys.index`). Las dos mitades se comprueban.
|
*/

/** Las rutas que Fortify publica cuando la feature está activa. */
$fortifyPasskeyRoutes = [
    'passkey.login-options',
    'passkey.login',
    'passkey.confirm-options',
    'passkey.confirm',
    'passkey.registration-options',
    'passkey.store',
    'passkey.destroy',
];

it('enables the fortify passkeys feature when the toggle is on', function (): void {
    expect(config('kore-app.auth.passkeys'))->toBeTrue()
        ->and(Features::enabled(Features::passkeys()))->toBeTrue();
});

it('registers every fortify passkey route when the toggle is on', function () use ($fortifyPasskeyRoutes): void {
    foreach ($fortifyPasskeyRoutes as $name) {
        expect(Route::has($name))->toBeTrue("Falta la ruta {$name}");
    }
});

it('registers the passkeys management screen when the toggle is on', function (): void {
    expect(Route::has('passkeys.index'))->toBeTrue();

    $route = Route::getRoutes()->getByName('passkeys.index');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('user/passkeys')
        // `password.confirm` en la pantalla, no sólo en los endpoints de
        // Fortify: si no, la confirmación saltaría a mitad de la ceremonia.
        ->and($route?->gatherMiddleware())->toContain('auth', 'verified', 'password.confirm');
});

it('asks fortify to require password confirmation on the management routes', function (): void {
    expect(config('fortify-options.passkeys.confirmPassword'))->toBeTrue();

    $destroy = Route::getRoutes()->getByName('passkey.destroy');

    expect($destroy?->gatherMiddleware())->toContain('password.confirm');
});

it('throttles the passkey routes with the passkeys limiter (R28)', function (): void {
    expect(config('fortify.limiters.passkeys'))->toBe('passkeys')
        ->and(RateLimiter::limiter('passkeys'))->not->toBeNull();

    $login = Route::getRoutes()->getByName('passkey.login');

    expect($login?->gatherMiddleware())->toContain('throttle:passkeys');
});

it('removes the fortify passkeys feature when the toggle is off', function (): void {
    Config::set('kore-app.auth.passkeys', false);

    $this->app->register(FortifyServiceProvider::class, force: true);

    expect(Features::enabled(Features::passkeys()))->toBeFalse();
});

it('does not read AUTH_PASSKEYS nor another config from the fortify config (R12/R17)', function (): void {
    // Sólo el código, sin comentarios: este archivo explica en prosa
    // precisamente lo que no puede hacer, y buscar el literal a pelo daría un
    // falso positivo contra su propia documentación.
    $code = '';

    foreach (token_get_all((string) file_get_contents(config_path('fortify.php'))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)
        ->not->toContain("env('AUTH_PASSKEYS'")
        ->not->toContain('config(');
});

it('drops every passkey route when the app boots with the toggle off', function () use ($fortifyPasskeyRoutes): void {
    withEnvironment(['AUTH_PASSKEYS' => 'false'], function () use ($fortifyPasskeyRoutes): void {
        expect(config('kore-app.auth.passkeys'))->toBeFalse()
            ->and(Features::enabled(Features::passkeys()))->toBeFalse()
            ->and(Route::has('passkeys.index'))->toBeFalse();

        foreach ($fortifyPasskeyRoutes as $name) {
            expect(Route::has($name))->toBeFalse("La ruta {$name} sigue registrada con el toggle apagado");
        }
    });
});

it('keeps the rest of the auth routes alive with the toggle off', function (): void {
    withEnvironment(['AUTH_PASSKEYS' => 'false'], function (): void {
        expect(Route::has('login'))->toBeTrue()
            ->and(Route::has('dashboard'))->toBeTrue();
    });
});
