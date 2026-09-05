<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use App\Core\Contracts\WebhookPublisher;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Webhooks\Console\Commands\WebhooksDispatchCommand;
use App\Modules\Webhooks\Console\Commands\WebhooksPruneCommand;
use App\Modules\Webhooks\Events\WebhookDeliveryQueued;
use App\Modules\Webhooks\Http\Livewire\FormComponent;
use App\Modules\Webhooks\Http\Livewire\ShowEndpoint;
use App\Modules\Webhooks\Http\Livewire\TableEndpoints;
use App\Modules\Webhooks\Http\Middleware\VerifyWebhookSignature;
use App\Modules\Webhooks\Listeners\DispatchWebhookDelivery;
use App\Modules\Webhooks\Listeners\PublishApiTokenIssued;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Policies\WebhookEndpointPolicy;
use App\Modules\Webhooks\Support\OutboxWebhookPublisher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Módulo Webhooks detrás de `WEBHOOKS_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni el binding de
 * `App\Core\Contracts\WebhookPublisher`, ni las rutas `/webhooks`, ni la policy,
 * ni el alias `webhook.signed`, ni los listeners, ni los comandos
 * `webhooks:dispatch` / `webhooks:prune`, ni sus traducciones. Un login por API
 * sigue funcionando exactamente igual, sólo que nadie se entera fuera.
 *
 * Quien resuelva el contrato con el toggle apagado recibe un
 * `BindingResolutionException` —«esta instalación no manda webhooks»—, que es
 * mejor respuesta que un envío silencioso a ninguna parte; por eso quien lo usa
 * pregunta antes por `config('kore-app.webhooks.enabled')`.
 *
 * **La migración es la excepción, y no es una válvula: es la regla.** Las tablas
 * se cargan siempre, también con el toggle apagado, porque un toggle apaga rutas
 * y comportamiento, nunca la forma de la base
 * (`docs/architecture/toggles.md`). El precedente es `AUTH_PASSKEYS=false`, que
 * deja de registrar la feature de Fortify y la pantalla mientras la tabla
 * `passkeys` se migra igual. Si la migración fuera condicional, dos
 * instalaciones del mismo commit tendrían bases distintas según el `.env` del
 * día en que se migró, y encender el toggle en producción exigiría una migración
 * a mano justo cuando ya hay tráfico.
 *
 * **Dos listeners y dos papeles distintos.** `DispatchWebhookDelivery` escucha
 * el evento propio del outbox y es quien entrega (en cola);
 * `PublishApiTokenIssued` escucha un evento de **Auth** y es el ejemplo
 * ejecutable de cómo se publica algo. Ése es todo el trato con Auth (R5):
 * `App\Modules\Auth\Events\*` es su frontera pública y Auth no sabe que este
 * módulo existe.
 *
 * Ver `docs/modules/webhooks.md`.
 */
final class WebhooksModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        'webhooks.table-endpoints' => TableEndpoints::class,
        'webhooks.form-component' => FormComponent::class,
        'webhooks.show-endpoint' => ShowEndpoint::class,
    ];

    public function register(): void
    {
        if (! $this->isWebhooksEnabled()) {
            return;
        }

        $this->app->singleton(WebhookPublisher::class, OutboxWebhookPublisher::class);
    }

    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre: el esquema no depende del toggle (ver el docblock).
        $this->loadMigrationsFrom("{$base}/Database/Migrations");

        // También siempre, y por la segunda excepción documentada de R10: un
        // espacio de vistas sin rutas que lo pinten no expone nada, y tenerlo
        // registrado evita que un `view('webhooks::…')` desde otro sitio
        // reviente en compilación en vez de dar un 404 limpio.
        $this->loadViewsFrom("{$base}/Resources/views", 'webhooks');

        if (! $this->isWebhooksEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadRoutesFrom("{$base}/Routes/web.php");

        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);

        /*
         * El alias existe, pero ninguna ruta del boilerplate lo lleva puesto:
         * `webhook.signed` es para el derivado que exponga un endpoint que
         * RECIBE webhooks de otro kore. Ver `docs/modules/webhooks.md`.
         */
        Route::aliasMiddleware('webhook.signed', VerifyWebhookSignature::class);

        // El outbox: publicar escribe la fila y dispara el evento; entregar es
        // cosa del listener en cola.
        Event::listen(WebhookDeliveryQueued::class, DispatchWebhookDelivery::class);

        // La única relación con Auth (R5), y el ejemplo ejecutable de cómo un
        // módulo publica los suyos.
        Event::listen(ApiTokenIssued::class, PublishApiTokenIssued::class);

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                WebhooksDispatchCommand::class,
                WebhooksPruneCommand::class,
            ]);
        }
    }

    private function isWebhooksEnabled(): bool
    {
        return (bool) config('kore-app.webhooks.enabled', false);
    }
}
