# Módulo Auth

**TL;DR**: Fortify maneja login/register/2FA/email-verify/password-reset con vistas Blade + koreUi (sin Flux UI). Sanctum agrega tokens API (toggle). spatie/laravel-permission da roles. Magic links via OTP. Socialite opt-in por proveedor.

## Ubicación

```
app/Modules/Auth/
├── Actions/                     # casos de uso propios (AuthUserRegisterAction)
├── Data/                        # DTOs: RegisterData, DashboardStatData
├── Database/
│   ├── Factories/               # ModuleFactory, RoleFactory
│   ├── Migrations/
│   └── Seeders/ModulesSeeder.php
├── Fortify/                     # ADAPTADORES de Fortify: CreateNewUser, ResetUserPassword,
│                                  UpdateUserPassword, UpdateUserProfileInformation,
│                                  PasswordValidationRules
├── Http/
│   ├── Controllers/SocialiteController.php
│   └── Livewire/                # Dashboard, MagicLink
├── Models/                      # Role, Module (+ ModulesCollection)
├── Providers/
│   ├── AuthModuleServiceProvider.php
│   └── FortifyServiceProvider.php
├── Resources/views/
│   ├── layouts/auth.blade.php
│   ├── livewire/dashboard.blade.php
│   └── pages/                   # login, register, forgot/reset-password, verify-email,
│                                  two-factor-challenge, confirm-password, magic-link
├── Routes/
│   ├── web.php                  # magic-link, socialite, /dashboard
│   └── api.php                  # /api/user (sólo si API_ENABLED)
├── Support/AuthorizationCatalog.php   # implementación del contrato de Core
└── Tests/Feature/               # Login, Register, PasswordReset, ApiToken, Dashboard, ...
```

### `Fortify/` no es `Actions/`

Los cinco stubs que publica Fortify (`CreateNewUser`, `ResetUserPassword`,
`UpdateUserPassword`, `UpdateUserProfileInformation` y el trait
`PasswordValidationRules`) son **adaptadores**: el paquete fija su nombre y la
firma del método por contrato, así que no pueden cumplir la regla 1 de
CLAUDE.md (sufijo `Action`, método `handle()`). Por eso viven en
`App\Modules\Auth\Fortify\` y no en `Actions/`, que queda reservada a los casos
de uso propios y sí está vigilada por los arch tests.

El adaptador valida la entrada HTTP y **delega**:

```php
// App\Modules\Auth\Fortify\CreateNewUser
public function create(array $input): User
{
    Validator::make($input, [...])->validate();

    return $this->registerUser->handle(new RegisterData(
        name: $input['name'],
        email: $input['email'],
        password: $input['password'],
    ));
}
```

`AuthUserRegisterAction` es entonces un caso de uso normal: se puede llamar
desde un comando o un job, y se testea sin tocar HTTP
(`Tests/Feature/AuthUserRegisterActionTest.php`).

> **Migración desde v1.0.0**: si tu proyecto importaba
> `App\Modules\Auth\Actions\Fortify\*`, cambia el namespace a
> `App\Modules\Auth\Fortify\*`. Los nombres de clase no cambian.

## Modelo User

`app/Models/User.php` (único modelo verdaderamente global) usa estos traits:

- `Laravel\Sanctum\HasApiTokens` — tokens API
- `Laravel\Fortify\TwoFactorAuthenticatable` — 2FA
- `Spatie\Permission\Traits\HasRoles` — roles + permisos
- `Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords` — magic links / OTP
- `Illuminate\Notifications\Notifiable`
- Implementa `Illuminate\Contracts\Auth\MustVerifyEmail`

## Rutas registradas por Fortify (automático)

| Verbo  | URI                            | Nombre                  |
|--------|--------------------------------|--------------------------|
| GET    | `/login`                       | `login`                  |
| POST   | `/login`                       | `login.store`            |
| POST   | `/logout`                      | `logout`                 |
| GET    | `/register`                    | `register`               |
| POST   | `/register`                    | `register.store`         |
| GET    | `/forgot-password`             | `password.request`       |
| POST   | `/forgot-password`             | `password.email`         |
| GET    | `/reset-password/{token}`      | `password.reset`         |
| POST   | `/reset-password`              | `password.update`        |
| GET    | `/email/verify`                | `verification.notice`    |
| POST   | `/email/verification-notification` | `verification.send`  |
| GET    | `/email/verify/{id}/{hash}`    | `verification.verify`    |
| GET    | `/two-factor-challenge`        | `two-factor.login`       |
| POST   | `/two-factor-challenge`        | `two-factor.login.store` |

## Rutas propias del módulo

| Verbo | URI                        | Nombre                | Toggle                  |
|-------|----------------------------|------------------------|-------------------------|
| GET   | `/magic-link`              | `magic-link.request`   | `AUTH_MAGIC_LINKS=true` |
| GET   | `/auth/{provider}/redirect`| `socialite.redirect`   | `AUTH_SOCIAL_LOGIN=true` |
| GET   | `/auth/{provider}/callback`| `socialite.callback`   | `AUTH_SOCIAL_LOGIN=true` |
| GET   | `/dashboard`               | `dashboard`            | requiere `auth + verified` |
| GET   | `/api/user`                | `api.user`             | `API_ENABLED=true`      |

### `/dashboard`

Es un **componente Livewire de página completa**
(`Route::get('/dashboard', Dashboard::class)`), no un `Route::view()`. Las
cifras (usuarios, permisos, módulos activos) se calculan en
`Auth\Http\Livewire\Dashboard::stats()` y viajan a la vista como
`DashboardStatData`, porque en las blades no se toca Eloquent. El layout y el
título los elige el propio `render()`:

```php
return view('auth::livewire.dashboard')
    ->layout('components.layouts.app', ['title' => __('Dashboard')]);
```

## Vistas y layout

Todas las páginas usan el layout component-style:

```blade
<x-auth::layouts.auth :title="__('Iniciar sesión')">
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <x-kore::input name="email" type="email" label="Email" />
        <x-kore::password name="password" label="Password" />
        <x-kore::button type="submit">Entrar</x-kore::button>
    </form>
</x-auth::layouts.auth>
```

Componentes koreUi usados: `<x-kore::input>`, `<x-kore::password>`, `<x-kore::button>`, `<x-kore::card>`, `<x-kore::alert>`, `<x-kore::input-otp>`, `<x-kore::checkbox>`.

## Toggles del módulo

Ver tabla completa en [`../architecture/toggles.md`](../architecture/toggles.md). Resumen relevante:

- **`API_ENABLED`** (default `true`): registra middleware `EnsureFrontendRequestsAreStateful` en grupo `api` y carga `Routes/api.php`.
- **`AUTH_2FA_ENABLED`** (default `true`): activa la feature
  `twoFactorAuthentication([confirm + confirmPassword])` de Fortify. Ruta
  `/two-factor-challenge` con `<x-kore::input-otp />`.

  Quien añade o quita esa feature es `FortifyServiceProvider::register()`,
  leyendo `config('kore-app.auth.two_factor')`; `config/fortify.php` NO la
  lista. No es un capricho: los archivos de config se cargan por orden
  alfabético y `fortify` va antes que `kore-app`, así que desde `fortify.php`
  el toggle todavía no existe. El `register()` de los providers, en cambio,
  corre con toda la config ya cargada y antes del `boot()` en el que Fortify
  publica sus rutas leyendo `fortify.features`.
- **`AUTH_MAGIC_LINKS`** (default `true`): registra `/magic-link` (Livewire). El componente envía OTP de 6 dígitos via `User::sendOneTimePassword()` y autentica con `attemptLoginUsingOneTimePassword`. Ver [Magic links](#magic-links-otp) para el detalle de anti-enumeración y throttle.
- **`AUTH_SOCIAL_LOGIN`** (default `false`): registra rutas socialite. Cada proveedor se controla por separado (`SOCIAL_GOOGLE`, `SOCIAL_GITHUB`); el controller `abort(404)` si el proveedor consultado no está habilitado en `config('kore-app.socialite.{provider}')`.

## Magic links (OTP)

`app/Modules/Auth/Http/Livewire/MagicLink.php`. Dos decisiones de seguridad que
el componente tiene que resolver por su cuenta, porque **las peticiones Livewire
viajan por `/livewire/update`** y ahí no corre ni el middleware de las rutas ni
el `limit_req` de nginx:

**1. Anti-enumeración.** `sendCode()` NO valida `exists:users,email`. Si el
correo no está registrado no se envía nada, pero `codeSent` pasa a `true` y la
UI muestra el mismo mensaje ambiguo («Si :email está registrado, te enviamos un
código»). Así el formulario no sirve para descubrir cuentas. `authenticate()`
devuelve el mismo error genérico tanto si el usuario no existe como si el código
es incorrecto.

**2. Throttle del envío.** `sendCode()` usa la facade `RateLimiter` con clave
`magic-link:{email}|{ip}`: **5 envíos cada 5 minutos**. Al excederlo se añade un
error de validación al campo `email` con los segundos restantes
(`RateLimiter::availableIn`) — nunca una excepción.

**Consumo del código**: no se duplica throttle. El paquete ya limita los
intentos dentro de `ConsumeOneTimePasswordAction`
(`config/one-time-passwords.php` → `rate_limit_attempts`: 5 intentos por usuario
cada 60 s).

Cubierto por `app/Modules/Auth/Tests/Feature/MagicLinkTest.php`.

## Rate limiting

| Limiter      | Dónde se define                                  | Límite                          |
|--------------|--------------------------------------------------|---------------------------------|
| `login`      | `FortifyServiceProvider::configureRateLimiting()` | 5/min por email+IP              |
| `two-factor` | `FortifyServiceProvider::configureRateLimiting()` | 5/min por sesión de login       |
| `api`        | `AuthModuleServiceProvider::configureApiRateLimiting()` | 60/min por usuario o IP  |
| magic link   | `MagicLink::sendCode()`                          | 5 envíos / 5 min por email+IP   |

`bootstrap/app.php` aplica `throttleApi()` al grupo `api` (el esqueleto de
Laravel 12 no lo trae) y configura `trustProxies`, sin el cual `$request->ip()`
sería siempre la IP interna del contenedor y el limiter por IP no serviría de
nada.

## Roles y permisos (spatie)

```php
use App\Models\User;

$user = User::find(1);

$user->assignRole('admin');
$user->givePermissionTo('edit articles');

$user->hasRole('admin');                   // bool
$user->can('edit articles');               // bool

// En blade:
@role('admin')   ...   @endrole
@can('edit articles')  ...  @endcan

// En rutas:
Route::middleware('role:admin')->group(...);
Route::middleware('permission:edit articles')->group(...);
```

Las migraciones spatie ya están aplicadas (`create_permission_tables`).

## API tokens (Sanctum)

```php
$token = $user->createToken('mobile-app')->plainTextToken;

// Cliente envía: Authorization: Bearer {token}
// Endpoint protegido: middleware('auth:sanctum')
```

`app/Modules/Auth/Routes/api.php`:

```php
Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api')
    ->group(function (): void {
        Route::get('/user', fn (Request $r) => $r->user())->name('api.user');
    });
```

## Tests

`app/Modules/Auth/Tests/Feature/`:

| Archivo | Qué cubre | Tests |
|---------|-----------|-------|
| `LoginTest` | página, login válido, login inválido, logout | 4 |
| `RegisterTest` | página, registro exitoso, email duplicado | 3 |
| `AuthUserRegisterActionTest` | `AuthUserRegisterAction`: crea, hashea y dispara el evento | 3 |
| `PasswordResetTest` | página, envío de notificación de reset | 2 |
| `ApiTokenTest` | `/api/user` con `Sanctum::actingAs` | 1 |
| `ApiRateLimitTest` | `throttle:api` en el grupo y limiter `api` registrado | 3 |
| `MagicLinkTest` | envío, rate limit, anti-enumeración, login con código | 6 |
| `AuthorizationSeederTest` | módulos, permisos y roles que siembra `ModulesSeeder` | 7 |
| `TwoFactorToggleTest` | el toggle `AUTH_2FA_ENABLED` añade/quita la feature y sus rutas | 5 |
| `DashboardTest` | el componente Livewire `Dashboard` y sus DTOs de cifras | 3 |
| `FactoryResolutionTest` | los modelos del módulo resuelven su factory dentro del módulo | 4 |

Total Auth: **41 tests / 113 assertions**. (Cifra real de
`./vendor/bin/pest app/Modules/Auth --compact`; actualízala cuando cambie.)

## Cómo extender

- **Agregar un proveedor Socialite** (ej. Twitter): añade `SOCIAL_TWITTER` a `config/kore-app.php`, configura `services.twitter` en `config/services.php`, y agrega `'twitter'` al array de proveedores válidos en `Routes/web.php`.
- **Cambiar la UI de auth**: edita las blades en `Resources/views/pages/`. El layout es `Resources/views/layouts/auth.blade.php`.
- **Forzar email verification en una ruta**: middleware `verified` (ya aplicado en `/dashboard`).
- **Cambiar el redirect post-login**: `config/fortify.php` → `'home' => '/dashboard'`.
