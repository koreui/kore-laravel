<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Console\ArchCheckCommand;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        $this->registerCoreCommands();
        $this->configureCommands();
        $this->configureModels();
        $this->configureFactories();
        $this->configureUrl();
        $this->configureDate();
        $this->configureAbout();
    }

    /**
     * Comandos de infraestructura de `App\Core`.
     *
     * Laravel sólo autodescubre `app/Console/Commands`, y el layout modular no
     * usa esa carpeta: los comandos de dominio viven en su módulo
     * (`App\Modules\{X}\Console\Commands`, registrados por el provider del
     * módulo) y los transversales aquí.
     */
    private function registerCoreCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchCheckCommand::class,
            ]);
        }
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

    /**
     * Factories por módulo.
     *
     * Laravel busca la factory de `App\Models\X` en `Database\Factories\XFactory`.
     * Con el layout modular eso obligaría a sacar todas las factories del
     * módulo al que pertenecen, así que se enseña al resolver la convención
     * del boilerplate:
     *
     *   App\Modules\{Mod}\Models\{X} → App\Modules\{Mod}\Database\Factories\{X}Factory
     *
     * El resto de modelos (empezando por `App\Models\User`) siguen resolviendo
     * a `database/factories/` como en un Laravel de serie.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(
            /**
             * @param class-string<Model> $modelName
             * @return class-string<Factory<Model>>
             */
            static function (string $modelName): string {
                if (str_starts_with($modelName, 'App\\Modules\\') && str_contains($modelName, '\\Models\\')) {
                    $factory = str_replace('\\Models\\', '\\Database\\Factories\\', $modelName).'Factory';
                } else {
                    // Igual que el resolver de Laravel: `App\Models\X` → `XFactory`
                    // y cualquier otro `App\...` conserva su subruta.
                    $relative = Str::startsWith($modelName, 'App\\Models\\')
                        ? Str::after($modelName, 'App\\Models\\')
                        : Str::after($modelName, 'App\\');

                    $factory = Factory::$namespace.$relative.'Factory';
                }

                if (! is_subclass_of($factory, Factory::class)) {
                    throw new InvalidArgumentException("No hay factory para [{$modelName}]: se buscó [{$factory}].");
                }

                return $factory;
            }
        );
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
