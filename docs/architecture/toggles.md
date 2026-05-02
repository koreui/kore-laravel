# Toggles del boilerplate

**TL;DR**: Todo lo opcional está detrás de un toggle en `config/kore-app.php` controlado por `.env`. Cuando un toggle está OFF, el ServiceProvider correspondiente debe hacer `return` temprano y NO registrar nada.

## Tabla de toggles

| Variable               | Default       | Activa                                              |
|------------------------|---------------|-----------------------------------------------------|
| `API_ENABLED`          | `true`        | Sanctum stateful middleware + rutas API (`/api/*`)  |
| `TENANCY_ENABLED`      | `false`       | Módulo Tenancy completo (stancl/tenancy)            |
| `TENANCY_MODE`         | `single-db`   | `single-db` o `multi-db`                            |
| `REVERB_ENABLED`       | `false`       | Laravel Reverb (websockets) — paquete no incluido por defecto |
| `OCTANE_ENABLED`       | `false`       | Octane / FrankenPHP — paquete no incluido por defecto |
| `OCTANE_SERVER`        | `frankenphp`  | servidor Octane                                     |
| `SCOUT_ENABLED`        | `false`       | Scout + Meilisearch — paquete no incluido por defecto |
| `SCOUT_DRIVER`         | `meilisearch` | driver Scout                                        |
| `AUTH_2FA_ENABLED`     | `true`        | Fortify TwoFactorAuthentication                     |
| `AUTH_MAGIC_LINKS`     | `true`        | OTP via spatie/laravel-one-time-passwords           |
| `AUTH_SOCIAL_LOGIN`    | `false`       | Socialite (con sub-toggles por proveedor)           |
| `SOCIAL_GOOGLE`        | `false`       | proveedor Google de Socialite                       |
| `SOCIAL_GITHUB`        | `false`       | proveedor GitHub de Socialite                       |
| `SENTRY_LARAVEL_DSN`   | (vacío)       | Sentry — sin DSN, el SDK es no-op                   |
| `PULSE_ENABLED`        | `false`       | Laravel Pulse                                       |

## Patrón en código

Lectura desde cualquier parte:

```php
if ((bool) config('kore-app.tenancy.enabled')) { ... }
```

Boot condicional dentro de un provider:

```php
public function register(): void
{
    if (! (bool) config('kore-app.tenancy.enabled')) {
        return;
    }

    $this->app->register(StanclTenancyServiceProvider::class);
}
```

Loading condicional de rutas API:

```php
if ((bool) config('kore-app.api.enabled')) {
    $this->loadRoutesFrom("{$base}/Routes/api.php");
}
```

## Reglas

- ❌ **No bypasear** los toggles con código directo (`if (env('TENANCY_ENABLED'))` o ENV reads ad-hoc). El boilerplate debe ser reusable.
- ❌ **No leer `env()` fuera de configs** — siempre `config()`. Los `.env` reads en code se rompen con `config:cache` (que se hace en producción).
- ✅ **Sí** agregar nuevos toggles a `config/kore-app.php` cuando una feature deba ser opt-in. Documenta la nueva variable en `.env.example` y aquí.

## Pennant para rollouts graduales

Los toggles globales son `true/false` por entorno. Para **feature flags por usuario / grupo / canary**, usa **Laravel Pennant**:

```php
use Laravel\Pennant\Feature;

Feature::define('new-billing-ui', fn (User $user) => $user->hasRole('beta-tester'));

if (Feature::active('new-billing-ui')) { ... }
```

Definir features en `app/Providers/AppServiceProvider::boot()` o en su propio `FeatureProvider`. Driver `database` para persistencia, `array` para tests.
