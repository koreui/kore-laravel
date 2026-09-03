<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Enums\SystemRole;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Override;

/**
 * Documentación OpenAPI de la API (dedoc/scramble), detrás del toggle
 * `API_DOCS` y del gate `viewApiDocs`.
 *
 * Sigue el patrón de `docs/patterns/toggle-provider.md`: apagado no registra
 * nada, y el `return` que importa es el de `register()`.
 *
 * **Por qué en `register()` y no en `boot()`.** Scramble expone sus rutas desde
 * el `booted()` de su propio provider, que arranca antes que los de la
 * aplicación (los de paquete van primero en `registerConfiguredProviders()`).
 * Cuando llega nuestro `boot()` la decisión ya está tomada; el `register()`
 * sí corre a tiempo.
 *
 * Ver `docs/guides/api.md`.
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        /*
         * Se escribe la bandera en los dos sentidos, y no sólo
         * `Scramble::ignoreDefaultRoutes()` cuando toca apagar, porque es
         * estado **estático**: sobrevive a un `refreshApplication()`. Un test
         * que arranca la aplicación con `API_DOCS=false` y después con `true`
         * (`withEnvironment()`, ver `docs/patterns/test-con-otro-entorno.md`)
         * heredaría el `true` de la corrida anterior y no vería ninguna ruta.
         */
        Scramble::$defaultRoutesIgnored = ! $this->docsEnabled();
    }

    public function boot(): void
    {
        if (! $this->docsEnabled()) {
            return;
        }

        $this->registerGate();
        $this->configureScramble();
    }

    /**
     * Quién puede abrir la documentación.
     *
     * En `local` no llega a preguntarse: el `RestrictedDocsAccess` de Scramble
     * deja pasar a cualquiera —incluido un invitado— cuando el entorno es
     * local, y es lo que se quiere en la máquina de quien la está escribiendo.
     * Fuera de local manda este gate y sólo entra el superadmin, por lo mismo
     * que Pulse y `/health`: la spec enumera todos los endpoints, sus
     * parámetros y sus errores.
     */
    private function registerGate(): void
    {
        Gate::define(
            'viewApiDocs',
            fn (?User $user): bool => $user instanceof User && $user->hasRole(SystemRole::Superadmin->value),
        );
    }

    private function configureScramble(): void
    {
        Scramble::configure()
            /*
             * `/api/docs` y `/api/docs.json`, no las `/docs/api` de fábrica: el
             * módulo Docs registra `GET /docs/{path}` con `where('path',
             * '[A-Za-z0-9_\-/]+')`, que casa `docs/api`, y sus rutas se
             * registran antes (Scramble espera al `booted()`), así que con los
             * dos toggles encendidos el visor de markdown se quedaba la URL de
             * la documentación de la API. Moverla la deja sin ambigüedad
             * posible, y de paso la agrupa con lo que documenta.
             */
            ->expose(
                ui: fn (Router $router, mixed $action): Route => $router->get('api/docs', $action)->name('scramble.docs.ui'),
                document: fn (Router $router, mixed $action): Route => $router->get('api/docs.json', $action)->name('scramble.docs.document'),
            )
            /*
             * Sólo se documentan las rutas versionadas (`api/v1/...`). El
             * filtro deja fuera `api/docs` y `api/docs.json` —la documentación
             * no se documenta a sí misma— y cualquier ruta interna que cuelgue
             * de `api/` sin versión.
             */
            ->routes(fn (Route $route): bool => Str::startsWith($route->uri(), 'api/v'))
            /*
             * Título y versión se ponen aquí y no en `config/scramble.php`
             * porque salen de `config/kore-api.php`, y un config no puede leer
             * otro (R12).
             */
            ->withDocumentTransformers(function (OpenApi $document): void {
                $document->info->title = config('app.name').' API';
                $document->info->version = (string) config('kore-api.version', 'v1');
            });
    }

    private function docsEnabled(): bool
    {
        return (bool) config('kore-app.api.enabled')
            && (bool) config('kore-api.docs.enabled');
    }
}
