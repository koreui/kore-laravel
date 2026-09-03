<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| R47 · APP_DEBUG=true no arranca en producción
|--------------------------------------------------------------------------
|
| El primer test arranca la aplicación de verdad con `APP_ENV=production` y
| `APP_DEBUG=true` (`withEnvironment()`, tests/Pest.php) y espera que el boot
| de `AppServiceProvider` la tire. Los otros dos no necesitan un arranque
| completo: re-registran el provider con `register(force: true)`, que ejecuta
| su `boot()` contra el entorno y la config que fija cada test.
|
*/

it('refuses to boot with APP_DEBUG=true in production', function (): void {
    expect(fn () => withEnvironment(
        ['APP_ENV' => 'production', 'APP_DEBUG' => 'true'],
        fn (): null => null,
    ))->toThrow(RuntimeException::class, 'APP_DEBUG=true en producción');
});

it('boots in production with APP_DEBUG=false', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    Config::set('app.env', 'production');
    Config::set('app.debug', false);

    $this->app->register(AppServiceProvider::class, force: true);

    expect($this->app->isProduction())->toBeTrue()
        ->and(config('app.debug'))->toBeFalse()
        // URL::forceHttps(), de AppServiceProvider::configureUrl().
        ->and(url('/'))->toStartWith('https://');
});

it('keeps debug allowed outside production', function (): void {
    Config::set('app.debug', true);

    $this->app->register(AppServiceProvider::class, force: true);

    expect($this->app->isProduction())->toBeFalse()
        ->and($this->app->environment())->toBe('testing')
        ->and(config('app.debug'))->toBeTrue();
});
