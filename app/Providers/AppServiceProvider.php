<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureCommands();
        $this->configureModels();
        $this->configureUrl();
        $this->configureDate();
        $this->configureAbout();
    }

    private function configureCommands(): void
    {
        Command::macro('isProduction', fn (): bool => $this->getLaravel()->isProduction());
    }

    /**
     * Nada de `Model::unguard()` global: desactivarlo para toda la app anula la
     * protección de mass assignment de TODOS los modelos (propios y de vendor).
     * Cada modelo declara su propio `$fillable` / `$guarded`; las factories ya
     * corren dentro de `Model::unguarded()` por su cuenta.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function configureUrl(): void
    {
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }

    private function configureDate(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function configureAbout(): void
    {
        $state = fn (mixed $enabled): string => $enabled ? 'enabled' : 'disabled';

        AboutCommand::add('Kore', [
            'Boilerplate' => 'kore-laravel',
            'Tenancy' => fn (): string => $state(config('kore-app.tenancy.enabled')),
            'API' => fn (): string => $state(config('kore-app.api.enabled')),
            '2FA' => fn (): string => $state(config('kore-app.auth.two_factor')),
            'Magic links' => fn (): string => $state(config('kore-app.auth.magic_links')),
            'Social login' => fn (): string => $state(config('kore-app.auth.social_login')),
            'Pulse' => fn (): string => $state(config('pulse.enabled')),
            'Sentry' => fn (): string => config('sentry.dsn') ? 'DSN configurado' : 'sin DSN',
        ]);
    }
}
