# Toggles del boilerplate

**TL;DR**: Todo lo opcional está detrás de un toggle en `config/kore-app.php` controlado por `.env`. Cuando un toggle está OFF, el ServiceProvider correspondiente debe hacer `return` temprano y NO registrar nada.

## Tabla de toggles

| Variable               | Default       | Activa                                              | Quién lo lee |
|------------------------|---------------|-----------------------------------------------------|--------------|
| `API_ENABLED`          | `true`        | Sanctum stateful middleware + rutas API (`/api/*`)  | `AuthModuleServiceProvider`, `routes/console.php` |
| `TENANCY_ENABLED`      | `false`       | Módulo Tenancy completo (stancl/tenancy)            | `TenancyModuleServiceProvider` |
| `BACKUP_ENABLED`       | `false`       | spatie/laravel-backup: comandos `backup:*`, las 3 tareas del scheduler y el `BackupsCheck` de `/health` | `BackupServiceProvider`, `routes/console.php` |
| `DOCS_ENABLED`         | `false`       | Visor de `docs/` en `/docs` (módulo Docs). Pensado para local; producción lo deja apagado | `DocsModuleServiceProvider` |
| `AUTH_2FA_ENABLED`     | `true`        | Fortify `twoFactorAuthentication` (rutas + pantalla)| `FortifyServiceProvider::register()` |
| `AUTH_MAGIC_LINKS`     | `true`        | OTP via spatie/laravel-one-time-passwords           | `Auth/Routes/web.php`, `login.blade.php` |
| `AUTH_SOCIAL_LOGIN`    | `false`       | Socialite (con sub-toggles por proveedor)           | `Auth/Routes/web.php`, `login.blade.php` |
| `AUTH_PASSKEYS`        | `true`        | Passkeys (WebAuthn) vía Fortify: rutas `passkey.*` + pantalla `/user/passkeys` | `FortifyServiceProvider::register()`, `Auth/Routes/web.php` |
| `SOCIAL_GOOGLE`        | `false`       | proveedor Google de Socialite                       | `SocialiteController`, `login.blade.php` |
| `SOCIAL_GITHUB`        | `false`       | proveedor GitHub de Socialite                       | `SocialiteController`, `login.blade.php` |

Estas diez son **todas** las claves de `config/kore-app.php`. La columna
"quién lo lee" no es decorativa: es la regla. Un toggle que nadie lee es una
mentira en la documentación, y por eso en la v1.0.0 se borraron
`REVERB_ENABLED`, `OCTANE_ENABLED`/`OCTANE_SERVER`, `SCOUT_ENABLED`/`SCOUT_DRIVER`,
`TENANCY_MODE`, `SENTRY_ENABLED` y el bloque `observability.*`.

**Y ya no depende de que alguien se acuerde: lo verifica el build.** El check
`R11` de `php artisan kore:arch:check` (`composer arch`, dentro de `composer ci`
y del pre-commit) aplana `config/kore-app.php` con `Arr::dot()` y busca cada
clave como `config('kore-app.{clave}')` en `app/`, `bootstrap/`, `config/`,
`database/`, `routes/`, `resources/` y `tests/`. La que no aparezca falla el
build con su nombre. Añadir un toggle sin lector, o borrar al último lector de
uno, se cae solo. Ver [`rules.md`](rules.md) → R11.

### Lo que NO es un toggle

| Tema | Cómo se controla de verdad |
|------|----------------------------|
| Reverb (websockets) | Módulo opcional. No está instalado; se añade con `composer require laravel/reverb` y su propia config. |
| Octane / FrankenPHP | Módulo opcional. `composer require laravel/octane`. |
| Scout + Meilisearch | Módulo opcional. `composer require laravel/scout`. |
| Modo de tenancy (`single-db` / `multi-db`) | Se decide al ejecutar `php artisan kore:tenancy:enable`, en `config/tenancy.php` (bootstrappers de stancl). Nunca fue una variable de entorno funcional. |
| Sentry | `SENTRY_LARAVEL_DSN`. Sin DSN el SDK es no-op; no hay booleano aparte. |
| Pulse | `PULSE_ENABLED`, que lee `config/pulse.php` (del paquete), no `kore-app`. |
| Health | Siempre activo. Rutas en `HealthServiceProvider`; `/health/json` pide `HEALTH_SECRET_TOKEN`. El `BackupsCheck` es la excepción: sólo se registra con `BACKUP_ENABLED=true`. |

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
- ℹ️ **Un toggle apaga rutas y UI, no el esquema.** `AUTH_PASSKEYS=false` deja de
  registrar la feature de Fortify y la pantalla, pero la tabla `passkeys` se
  migra igual: una migración condicional produciría bases distintas según el
  `.env` del día en que se migró, y un boilerplate reutilizable no puede
  permitírselo.
- ⚠️ **Un config no puede leer otro config.** Laravel carga `config/*.php` en orden alfabético, así que `config/fortify.php` NO puede hacer `config('kore-app.auth.two_factor')`: cuando se evalúa, `kore-app` todavía no existe. La salida es mutar la config del paquete desde el `register()` del provider del módulo, que corre después de cargar toda la config y antes del `boot()` que registra las rutas. Es exactamente lo que hace `FortifyServiceProvider::configureTwoFactorFeature()`.

## Pennant para rollouts graduales

Los toggles globales son `true/false` por entorno. Para **feature flags por usuario / grupo / canary**, usa **Laravel Pennant**:

```php
use Laravel\Pennant\Feature;

Feature::define('new-billing-ui', fn (User $user) => $user->hasRole('beta-tester'));

if (Feature::active('new-billing-ui')) { ... }
```

Definir features en `app/Providers/AppServiceProvider::boot()` o en su propio `FeatureProvider`. Driver `database` para persistencia, `array` para tests.
