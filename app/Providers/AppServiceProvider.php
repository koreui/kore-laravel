<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Console\AgentsSyncCommand;
use App\Core\Console\ArchCheckCommand;
use App\Core\Console\ChangelogSectionCommand;
use App\Core\Console\Hooks\PrePushCommand;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Override;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->refuseToBootWithDebugInProduction();
        $this->registerCoreCommands();
        $this->configureApiRateLimiters();
        $this->configureCommands();
        $this->configureModels();
        $this->configureFactories();
        $this->configureUrl();
        $this->configureDate();
        $this->configureAbout();
    }

    /**
     * Producción con `APP_DEBUG=true` no arranca.
     *
     * La pantalla de error de Laravel en modo debug vuelca el `.env` **entero**
     * —APP_KEY, credenciales de base de datos, tokens de terceros— junto al
     * stack trace, a quien provoque cualquier excepción. Un `.env` mal copiado
     * es un error de un carácter y no da ninguna señal hasta que alguien ve el
     * volcado, así que la señal la damos aquí: la aplicación se niega a
     * levantar en vez de exponerse en silencio.
     *
     * @throws RuntimeException
     */
    private function refuseToBootWithDebugInProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if (! (bool) config('app.debug')) {
            return;
        }

        throw new RuntimeException(
            'APP_DEBUG=true en producción expone variables de entorno, stack traces y '
            .'credenciales en cada error; ponlo en false y vuelve a cachear la config '
            .'(php artisan config:cache).'
        );
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
                AgentsSyncCommand::class,
                ArchCheckCommand::class,
                ChangelogSectionCommand::class,
                // Reemplaza al del paquete: acepta los argumentos que git pasa al pre-push.
                PrePushCommand::class,
            ]);
        }
    }

    /**
     * Los tres limiters nombrados de la API, con sus cifras en
     * `config/kore-api.php` (R28).
     *
     *   `api`         · el del grupo `api` (`throttleApi()` en bootstrap/app.php).
     *                   Por usuario autenticado, o por IP si no lo hay.
     *   `api-auth`    · login, registro, magic link, refresco de token. **Por
     *                   IP**, a propósito: quien fuerza credenciales todavía no
     *                   tiene usuario, así que limitar por usuario no limita
     *                   nada.
     *   `api-uploads` · subidas de archivo, por usuario. Son caras en CPU, en
     *                   memoria y en disco, y su límite razonable no tiene nada
     *                   que ver con el de una lectura.
     *
     * Se registran **siempre**, también con `API_ENABLED=false`: el toggle
     * decide si se cargan las rutas, no si existe el limiter. Y sin
     * `RateLimiter::for('api')`, el `throttle:api` del grupo degradaría a
     * `maxAttempts = (int) 'api' = 0` y bloquearía todas las peticiones —
     * Laravel (12 y 13) no trae ninguno de fábrica.
     *
     * Viven aquí y no en `AuthModuleServiceProvider` desde la v2.2.0: son parte
     * del contrato de la API (`App\Core\Http\Api`), que ningún módulo posee.
     * `api-uploads` no tiene nada que ver con la autenticación.
     */
    private function configureApiRateLimiters(): void
    {
        /** @var array<string, int> $limiters */
        $limiters = (array) config('kore-api.limiters', []);

        $byUserOrIp = static fn (int $perMinute): callable => static fn (Request $request): Limit => Limit::perMinute($perMinute)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('api', $byUserOrIp((int) ($limiters['api'] ?? 60)));

        RateLimiter::for('api-uploads', $byUserOrIp((int) ($limiters['api-uploads'] ?? 30)));

        RateLimiter::for(
            'api-auth',
            static fn (Request $request): Limit => Limit::perMinute((int) ($limiters['api-auth'] ?? 5))
                ->by((string) $request->ip()),
        );
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
            'API docs' => fn (): string => $state(config('kore-api.docs.enabled')),
            '2FA' => fn (): string => $state(config('kore-app.auth.two_factor')),
            'Passkeys' => fn (): string => $state(config('kore-app.auth.passkeys')),
            'Magic links' => fn (): string => $state(config('kore-app.auth.magic_links')),
            'Social login' => fn (): string => $state(config('kore-app.auth.social_login')),
            'Social Google' => fn (): string => $state(config('kore-app.socialite.google')),
            'Social GitHub' => fn (): string => $state(config('kore-app.socialite.github')),
            'Docs' => fn (): string => $state(config('kore-app.docs.enabled')),
            'Devices' => fn (): string => $state(config('kore-app.devices.enabled')),
            'PDF' => fn (): string => config('kore-app.pdf.enabled')
                ? 'enabled (driver '.config('laravel-pdf.driver').')'
                : 'disabled',
            'Files' => fn (): string => config('kore-app.files.enabled')
                ? 'enabled (disco '.config('files.disk').')'
                : 'disabled',
            'Webhooks' => fn (): string => config('kore-app.webhooks.enabled')
                ? 'enabled ('.count((array) config('kore-webhooks.events', [])).' eventos en el catálogo)'
                : 'disabled',
            'Backup' => fn (): string => config('kore-app.backup.enabled')
                ? 'enabled'.(config('backup.backup.password') ? ' (zip cifrado)' : ' (zip SIN cifrar)')
                : 'disabled',
            'Pulse' => fn (): string => $state(config('pulse.enabled')),
            'Sentry' => fn (): string => config('sentry.dsn') ? 'DSN configurado' : 'sin DSN',
        ]);
    }
}
