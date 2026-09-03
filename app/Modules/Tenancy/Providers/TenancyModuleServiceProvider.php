<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\Commands\EnableTenancyCommand;
use Illuminate\Support\ServiceProvider;
use Override;
use Stancl\Tenancy\TenancyServiceProvider as StanclTenancyServiceProvider;

final class TenancyModuleServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // El comando vive siempre disponible para que el usuario pueda activar
        // tenancy con `php artisan kore:tenancy:enable`.
        $this->commands([
            EnableTenancyCommand::class,
        ]);

        if (! $this->isTenancyEnabled()) {
            return;
        }

        $this->app->register(StanclTenancyServiceProvider::class);
    }

    public function boot(): void
    {
        if (! $this->isTenancyEnabled()) {
            return;
        }

        $base = __DIR__.'/..';

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");

        // `kore:tenancy:enable` publica aquí las migraciones de stancl. En un
        // clon fresco la carpeta puede no existir todavía (sólo lleva .gitkeep),
        // y loadMigrationsFrom() con una ruta inexistente revienta al migrar.
        if (is_dir($migrations = "{$base}/Database/Migrations")) {
            $this->loadMigrationsFrom($migrations);
        }

        if (file_exists($routes = "{$base}/Routes/tenant.php")) {
            $this->loadRoutesFrom($routes);
        }
    }

    private function isTenancyEnabled(): bool
    {
        return (bool) config('kore-app.tenancy.enabled', false);
    }
}
