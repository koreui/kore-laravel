<?php

declare(strict_types=1);

use App\Modules\Auth\Providers\FortifyServiceProvider;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

it('enables the fortify two factor feature when the toggle is on', function (): void {
    expect(config('kore-app.auth.two_factor'))->toBeTrue()
        ->and(Features::enabled(Features::twoFactorAuthentication()))->toBeTrue();
});

it('registers the two-factor challenge route when the toggle is on', function (): void {
    expect(Route::has('two-factor.login'))->toBeTrue();
});

it('removes the fortify two factor feature when the toggle is off', function (): void {
    Config::set('kore-app.auth.two_factor', false);

    $this->app->register(FortifyServiceProvider::class, force: true);

    expect(Features::enabled(Features::twoFactorAuthentication()))->toBeFalse();
});

it('does not read AUTH_2FA_ENABLED from env in the fortify config', function (): void {
    expect(file_get_contents(config_path('fortify.php')))
        ->not->toContain("env('AUTH_2FA_ENABLED'");
});

it('drops the two-factor routes when the app boots with the toggle off', function (): void {
    Env::getRepository()->set('AUTH_2FA_ENABLED', 'false');

    try {
        $this->refreshApplication();

        expect(config('kore-app.auth.two_factor'))->toBeFalse()
            ->and(Features::enabled(Features::twoFactorAuthentication()))->toBeFalse()
            ->and(Route::has('two-factor.login'))->toBeFalse();
    } finally {
        Env::getRepository()->clear('AUTH_2FA_ENABLED');
    }
});
