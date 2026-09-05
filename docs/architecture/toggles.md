# Toggles del boilerplate

**TL;DR**: Todo lo opcional está detrás de un toggle en `config/kore-app.php` controlado por `.env`. Cuando un toggle está OFF, el ServiceProvider correspondiente debe hacer `return` temprano y NO registrar nada.

## Tabla de toggles

| Variable               | Default       | Activa                                              | Quién lo lee |
|------------------------|---------------|-----------------------------------------------------|--------------|
| `API_ENABLED`          | `true`        | Sanctum stateful middleware + rutas API (`/api/v1/*`)  | `AuthModuleServiceProvider`, `ApiDocsServiceProvider`, `routes/console.php` |
| `TENANCY_ENABLED`      | `false`       | Módulo Tenancy completo (stancl/tenancy)            | `TenancyModuleServiceProvider` |
| `BACKUP_ENABLED`       | `false`       | spatie/laravel-backup: comandos `backup:*`, las 3 tareas del scheduler y el `BackupsCheck` de `/health` | `BackupServiceProvider`, `routes/console.php` |
| `DOCS_ENABLED`         | `false`       | Visor de `docs/` en `/docs` (módulo Docs). Pensado para local; producción lo deja apagado | `DocsModuleServiceProvider` |
| `DEVICES_ENABLED`      | `false`       | Módulo Devices: rutas `api/v1/devices/*`, listeners de `ApiTokenIssued`/`ApiTokenRevoked`, alias `devices.version` y `devices:cleanup` en el scheduler. **La tabla `devices` se migra igual** con el toggle apagado | `DevicesModuleServiceProvider`, `routes/console.php`, `AppServiceProvider::configureAbout()` |
| `PDF_ENABLED`          | `false`       | Módulo Pdf: binding de `App\Core\Contracts\PdfRenderer`, gate `viewPdfPreview` y rutas `/pdf/preview*`. Opt-in porque el motor es un **servicio aparte** (Gotenberg). **No tiene tablas**, así que aquí no hay nada que migrar | `PdfModuleServiceProvider`, `AppServiceProvider::configureAbout()` |
| `FILES_ENABLED`        | `false`       | Módulo Files: binding de `App\Core\Contracts\FileStore`, ruta firmada `files.serve`, listeners de compresión/sincronización y `files:cleanup` en el scheduler. **La tabla `media` y el espacio de vistas `files::` se registran igual** con el toggle apagado (ver abajo) | `FilesModuleServiceProvider`, `routes/console.php`, `AppServiceProvider::configureAbout()`, `Users\Http\Livewire\{FormComponent, TableUsers}` |
| `NOTIFICATIONS_ENABLED` | `false`       | Módulo Notifications: binding de `App\Core\Contracts\Notifier`, campana del encabezado, rutas `/notifications*` y `api/v1/me/notifications*`, listener de `ApiTokenIssued` y `notifications:prune` en el scheduler. **Las tablas `notifications` y `notification_preferences` y el espacio de vistas `notifications::` se registran igual** con el toggle apagado | `NotificationsModuleServiceProvider`, `routes/console.php`, `AppServiceProvider::configureAbout()`, `resources/views/components/layouts/app.blade.php` |
| `E2E_HARNESS`          | `false`       | Harness de la suite E2E: rutas `/__e2e__/*` (módulo E2E). Sólo lo enciende `.env.e2e`, y aun así hacen falta el entorno y una base de pruebas | `E2EModuleServiceProvider` vía `HarnessGuard` |
| `AUTH_2FA_ENABLED`     | `true`        | Fortify `twoFactorAuthentication` (rutas + pantalla)| `FortifyServiceProvider::register()` |
| `AUTH_MAGIC_LINKS`     | `true`        | OTP via spatie/laravel-one-time-passwords           | `Auth/Routes/web.php`, `login.blade.php` |
| `AUTH_SOCIAL_LOGIN`    | `false`       | Socialite (con sub-toggles por proveedor)           | `Auth/Routes/web.php`, `login.blade.php` |
| `AUTH_PASSKEYS`        | `true`        | Passkeys (WebAuthn) vía Fortify: rutas `passkey.*` + pantalla `/user/passkeys` | `FortifyServiceProvider::register()`, `Auth/Routes/web.php` |
| `AUTH_INVITATIONS`     | `false`       | Registro por invitación y estado de alta: campo `invitation_code` en `/register`, pantallas `/invitations*` (permiso `invitations.manage`), pantalla de espera `/account/pending`, middleware `account.active` sobre los grupos `web` y `api`, panel de estado en `/users/{id}/edit` y `invitations:prune` en el scheduler. **La tabla `invitation_codes`, las columnas `account_status`/`activated_at` y el permiso `invitations.manage` existen igual** con el toggle apagado | `AuthModuleServiceProvider`, `Auth/Routes/web.php`, `Auth/Fortify/CreateNewUser`, `SocialiteController`, `UsersModuleServiceProvider`, `Users\Resources/views/pages/edit`, `resources/views/components/layouts/app.blade.php`, `routes/console.php`, `AppServiceProvider::configureAbout()` |
| `SOCIAL_GOOGLE`        | `false`       | proveedor Google de Socialite                       | `SocialiteController`, `login.blade.php` |
| `SOCIAL_GITHUB`        | `false`       | proveedor GitHub de Socialite                       | `SocialiteController`, `login.blade.php` |

Estas catorce son **todas** las claves de `config/kore-app.php`. La columna
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
| Documentación OpenAPI (`API_DOCS`) | **Sí es un toggle, pero no vive aquí**: es un parámetro del contrato de la API, así que está en `config/kore-api.php` (`kore-api.docs.enabled`) junto a la versión, la paginación y los limiters. Lo lee `ApiDocsServiceProvider`, apaga las rutas de Scramble en su `register()` y por defecto es `false`. Ver [`../guides/api.md`](../guides/api.md). |
| Versión, paginación y limiters de la API | `config/kore-api.php`. No son booleanos: son las cifras del contrato. |
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

## `config/kore-api.php` no es `config/kore-app.php`

`kore-app` responde «¿qué capacidades del boilerplate están encendidas?»;
`kore-api` responde «¿cómo se comporta la API cuando lo está?». Por eso el
toggle sigue siendo uno solo (`API_ENABLED`) y las cifras del contrato —versión,
paginación, limiters, documentación— viven en su propio archivo: un derivado que
quiera 200 filas por página o un `api-auth` más estricto no tiene que tocar la
lista de toggles.

El check R11 sólo mira `kore-app`, a propósito: `kore-api` no declara
capacidades, declara parámetros, y un parámetro sin lector no miente sobre lo
que la aplicación hace. Ver [`../guides/api.md`](../guides/api.md).

`config/devices.php`, `config/files.php` y `config/kore-notifications.php`
siguen el mismo reparto respecto de sus módulos: el toggle vive en `kore-app` y
las cifras —plataformas, discos, categorías, plazos de purga— en su propio
archivo, con un test que hace de check R11 para cada uno.

## Reglas

- ❌ **No bypasear** los toggles con código directo (`if (env('TENANCY_ENABLED'))` o ENV reads ad-hoc). El boilerplate debe ser reusable.
- ❌ **No leer `env()` fuera de configs** — siempre `config()`. Los `.env` reads en code se rompen con `config:cache` (que se hace en producción).
- ✅ **Sí** agregar nuevos toggles a `config/kore-app.php` cuando una feature deba ser opt-in. Documenta la nueva variable en `.env.example` y aquí.
- ℹ️ **Un toggle apaga rutas y UI, no el esquema.** `AUTH_PASSKEYS=false` deja de
  registrar la feature de Fortify y la pantalla, pero la tabla `passkeys` se
  migra igual: una migración condicional produciría bases distintas según el
  `.env` del día en que se migró, y un boilerplate reutilizable no puede
  permitírselo.
- ℹ️ **Un toggle tampoco apaga un espacio de vistas con componentes.** Blade
  compila las etiquetas `<x-modulo::algo>` al compilar la plantilla que las usa,
  no al ejecutarla: un `@if (config(…))` alrededor no evita la resolución. Por
  eso `FilesModuleServiceProvider` hace su `loadViewsFrom` **antes** del early
  return, igual que la migración. Registrar un espacio de vistas que ninguna
  ruta pinta no expone nada; no hacerlo devolvía un 500 en la pantalla de
  usuarios con `FILES_ENABLED=false`.
- ⚠️ **Un config no puede leer otro config.** Laravel carga `config/*.php` en orden alfabético, así que `config/fortify.php` NO puede hacer `config('kore-app.auth.two_factor')`: cuando se evalúa, `kore-app` todavía no existe. La salida es mutar la config del paquete desde el `register()` del provider del módulo, que corre después de cargar toda la config y antes del `boot()` que registra las rutas. Es exactamente lo que hace `FortifyServiceProvider::configureTwoFactorFeature()`.

## Pennant para rollouts graduales

Los toggles globales son `true/false` por entorno. Para **feature flags por usuario / grupo / canary**, usa **Laravel Pennant**:

```php
use Laravel\Pennant\Feature;

Feature::define('new-billing-ui', fn (User $user) => $user->hasRole('beta-tester'));

if (Feature::active('new-billing-ui')) { ... }
```

Definir features en `app/Providers/AppServiceProvider::boot()` o en su propio `FeatureProvider`. Driver `database` para persistencia, `array` para tests.
