# Módulo Auth

**TL;DR**: Fortify maneja login/register/2FA/email-verify/password-reset con vistas Blade + koreUi (sin Flux UI). Sanctum agrega tokens API (toggle). spatie/laravel-permission da roles. Magic links via OTP. Passkeys (WebAuthn) vía Fortify, toggle `AUTH_PASSKEYS`. Socialite opt-in por proveedor.

## Ubicación

```
app/Modules/Auth/
├── Actions/                     # casos de uso propios (AuthUserRegisterAction, AuthPasskeyDeleteAction)
├── Data/                        # DTOs: RegisterData, DashboardStatData, PasskeyData
├── Database/
│   ├── Factories/               # ModuleFactory, RoleFactory
│   ├── Migrations/
│   └── Seeders/ModulesSeeder.php
├── Fortify/                     # ADAPTADORES de Fortify: CreateNewUser, ResetUserPassword,
│                                  UpdateUserPassword, UpdateUserProfileInformation,
│                                  PasswordValidationRules
├── Http/
│   ├── Controllers/SocialiteController.php
│   └── Livewire/                # Dashboard, MagicLink, Passkeys
├── Models/                      # Role, Module (+ ModulesCollection)
├── Policies/PasskeyPolicy.php   # sólo el dueño revoca su credencial
├── Providers/
│   ├── AuthModuleServiceProvider.php
│   └── FortifyServiceProvider.php
├── Resources/views/
│   ├── layouts/auth.blade.php
│   ├── livewire/                # dashboard, passkeys
│   └── pages/                   # login, register, forgot/reset-password, verify-email,
│                                  two-factor-challenge, confirm-password, magic-link
├── Routes/
│   ├── web.php                  # magic-link, socialite, /dashboard, /user/passkeys
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
- `Laravel\Fortify\PasskeyAuthenticatable` — passkeys (WebAuthn); implementa también `Laravel\Fortify\Contracts\PasskeyUser`
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
| GET   | `/user/passkeys`           | `passkeys.index`       | `AUTH_PASSKEYS=true` (+ `auth + verified + password.confirm`) |
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
- **`AUTH_PASSKEYS`** (default `true`): activa la feature `passkeys` de Fortify
  (sus rutas `passkey.*`) y la pantalla `/user/passkeys`. Mismo mecanismo que el
  2FA: lo lee `FortifyServiceProvider::register()`, no `config/fortify.php`. Ver
  [Passkeys](#passkeys-webauthn).
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

## Passkeys (WebAuthn)

`AUTH_PASSKEYS=true` (default). Entrar con la huella, la cara o el PIN del
dispositivo, sin escribir la contraseña.

El boilerplate **no implementa WebAuthn**: lo pone `laravel/passkeys`, y Fortify
lo envuelve (`Features::passkeys`), publica las rutas y copia la configuración.
Lo que aporta el módulo Auth son cuatro cosas: el toggle, la pantalla de
gestión, el botón del login y el rate limit.

### Qué hace cada pieza

| Pieza | Dónde | Qué aporta |
| --- | --- | --- |
| `FortifyServiceProvider::configurePasskeysFeature()` | `Auth/Providers/` | Añade o quita `Features::passkeys(['confirmPassword' => true])` de `fortify.features` según el toggle (variante B del patrón, igual que el 2FA). |
| `config/fortify.php` → `passkeys` | `config/` | RP id, orígenes, secreto del user handle y timeout. Fortify los copia a `config/passkeys.php` en su `register()`; **no publiques la config del paquete**. |
| `App\Modules\Auth\Http\Livewire\Passkeys` | `Auth/Http/Livewire/` | Pantalla `/user/passkeys`: lista, alta y revocación. |
| `App\Modules\Auth\Actions\AuthPasskeyDeleteAction` | `Auth/Actions/` | La revocación como caso de uso (delega en `Laravel\Passkeys\Actions\DeletePasskey` para que el evento `PasskeyDeleted` salga igual por los dos caminos). |
| `App\Modules\Auth\Policies\PasskeyPolicy` | `Auth/Policies/` | «Sólo el dueño». Registrada a mano en `AuthModuleServiceProvider` porque el modelo es de vendor. |
| `App\Modules\Auth\Data\PasskeyData` | `Auth/Data/` | Lo que ve la Blade: id, nombre, autenticador y fechas ya formateadas. Ni `credential_id` ni `credential` salen del servidor. |
| `korePasskeys` (Alpine) | `resources/js/app.js` | Envuelve al cliente oficial `@laravel/passkeys` y traduce sus errores por `error.name`, no por su texto (R33). |
| `database/migrations/2026_09_03_000000_create_passkeys_table.php` | `database/migrations/` | La tabla `passkeys`, publicada del paquete y adaptada (R29: `down()` explícito). |

### Rutas

Fortify publica **siete** rutas (`passkey.*`, en singular) y el limiter cuelga de
seis: la de borrado no lo lleva;
la pantalla es del **módulo** (`passkeys.index`, en plural).

| Verbo | URI | Nombre | Middleware |
| --- | --- | --- | --- |
| GET | `/passkeys/login/options` | `passkey.login-options` | `guest` + `throttle:passkeys` |
| POST | `/passkeys/login` | `passkey.login` | `guest` + `throttle:passkeys` |
| GET | `/passkeys/confirm/options` | `passkey.confirm-options` | `auth` + `throttle:passkeys` |
| POST | `/passkeys/confirm` | `passkey.confirm` | `auth` + `throttle:passkeys` |
| GET | `/user/passkeys/options` | `passkey.registration-options` | `auth` + `password.confirm` + `throttle:passkeys` |
| POST | `/user/passkeys` | `passkey.store` | `auth` + `password.confirm` + `throttle:passkeys` |
| DELETE | `/user/passkeys/{passkey}` | `passkey.destroy` | `auth` + `password.confirm` |
| GET | `/user/passkeys` | `passkeys.index` | `auth` + `verified` + `password.confirm` |

`/passkeys/confirm*` es el atajo bonito de Fortify: confirma la contraseña de la
sesión **con una passkey** en vez de escribiéndola. El boilerplate deja las
rutas publicadas pero todavía no las usa desde la UI.

### Por qué `password.confirm` también en la pantalla

Con `confirmPassword => true`, Fortify exige contraseña confirmada en sus
endpoints de gestión. Si la pantalla no lo pidiera también, la confirmación
saltaría **a mitad del flujo**: el usuario escribe el nombre, el navegador le
pide la huella, y sólo entonces el POST se va a `/user/confirm-password` — con
la credencial recién creada perdida por el camino. Con el middleware en la
pantalla, la contraseña se confirma antes de empezar.

Y por eso el componente vuelve a comprobarlo dentro de `deletePasskey()`
(R23): la llamada viaja por `/livewire/update`, donde el middleware de la ruta
no corre, y con la pantalla abierta la ventana de confirmación
(`auth.password_timeout`, 3 h) puede haber caducado.

### Autorización de la revocación

Tres barreras, en este orden:

1. **La ventana de confirmación de contraseña** sigue viva (si no, `423`).
2. **La propiedad, por consulta**: la credencial se busca dentro de
   `$user->passkeys()`, así que un id ajeno es un `404`. Esto es lo que
   realmente protege, porque el `Gate::before` del superadmin devuelve `true`
   ante cualquier ability y la policy no lo frenaría.
3. **`PasskeyPolicy::delete()`** (R25), como segunda barrera y punto único de la
   regla.

Una passkey no es un recurso administrable: no hay permiso `passkeys.delete` ni
rol que alcance la de otro. El soporte que necesite revocar una credencial ajena
usa `AuthPasskeyDeleteAction` desde consola, que es donde queda registrado.

### Configuración: RP id y orígenes en producción

```php
// config/fortify.php
'passkeys' => [
    'relying_party_id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
    'allowed_origins' => [(string) env('APP_URL', 'http://localhost')],
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: env('APP_KEY'),
    'timeout' => 60000,
],
```

Cuatro cosas que hay que saber antes de desplegar:

- **`relying_party_id` es el dominio, y es permanente.** Las credenciales quedan
  atadas a él. Cambiar de `app.ejemplo.com` a `ejemplo.com` **invalida todas las
  passkeys registradas**; no hay migración posible, los usuarios tienen que
  registrarlas de nuevo. Si el dominio definitivo no está decidido, mejor
  esperar a decidirlo antes de encender esto en producción.
- **WebAuthn exige contexto seguro.** `https://` siempre, salvo `localhost`.
  Un `APP_URL` con IP (`http://127.0.0.1:8000`) **no vale**: el RP id tiene que
  ser un dominio y Chrome rechaza los literales IP.
- **`allowed_origins` sale de `APP_URL`.** Si sirves el mismo sitio en `www` y
  sin `www`, el origen que no esté en la lista falla; se añade en el config, que
  es donde se revisa en el diff (misma decisión que R46 con la CSP).
- **`user_handle_secret` deriva de `APP_KEY`.** Rotar `APP_KEY` sin fijar antes
  `PASSKEYS_USER_HANDLE_SECRET` cambia el user handle de todos e invalida las
  credenciales. Si vas a rotar, escribe el valor viejo en esa variable primero.

`config/passkeys.php` **no se publica**: Fortify sobrescribe sus claves desde
`configurePasskeys()`, así que un archivo publicado sólo serviría para mentir.

### Rate limit (R28)

`fortify.limiters.passkeys => 'passkeys'`, y el limiter se define en
`FortifyServiceProvider::configureRateLimiting()`: **30/min**, por usuario
cuando lo hay y por IP cuando no. Dos matices que explican el número: una
ceremonia gasta **dos** peticiones (options + submit), y el cubo de los
invitados lo comparte toda una oficina detrás del mismo NAT. Aquí el límite no
es la defensa principal —adivinar una firma WebAuthn no es cuestión de
intentos—, es el freno al abuso del endpoint.

### Cómo probarlo

En local, con `APP_URL=http://localhost:8000` (no `127.0.0.1`):

```bash
composer dev
# 1. Entra con contraseña, ve a /user/passkeys (te pedirá confirmar la contraseña)
# 2. Ponle nombre y pulsa «Registrar passkey» → Touch ID / Windows Hello
# 3. Cierra sesión y usa «Entrar con passkey» en /login
```

Sin dispositivo biométrico, Chrome trae un autenticador virtual en
**DevTools → ⋮ → More tools → WebAuthn**.

Automatizado:

- `app/Modules/Auth/Tests/Feature/PasskeysToggleTest.php` — el toggle y sus rutas.
- `app/Modules/Auth/Tests/Feature/PasskeysScreenTest.php` — acceso, listado y
  revocación.
- `tests/e2e/specs/auth/passkeys.spec.ts` — la ceremonia real, con el
  autenticador virtual de CDP (ver `docs/quality/e2e.md`).

### Límites de lo que hay hoy

- La UI **no usa** `/passkeys/confirm*`: confirmar la contraseña con una passkey
  está publicado pero no enganchado.
- No hay **autofill** (`Passkeys.autofill()`, el desplegable del navegador sobre
  el campo de email): el botón explícito es más fácil de testear y de explicar.
- No se renombra una passkey ni se ve desde qué navegador se usó por última vez;
  sólo nombre, autenticador (por AAGUID), alta y último uso.
- Registrar una passkey **no** sustituye ni desactiva el 2FA: son dos caminos
  independientes y ambos toggles conviven.

## Rate limiting

| Limiter      | Dónde se define                                  | Límite                          |
|--------------|--------------------------------------------------|---------------------------------|
| `login`      | `FortifyServiceProvider::configureRateLimiting()` | 5/min por email+IP              |
| `two-factor` | `FortifyServiceProvider::configureRateLimiting()` | 5/min por sesión de login       |
| `api`        | `AuthModuleServiceProvider::configureApiRateLimiting()` | 60/min por usuario o IP  |
| magic link   | `MagicLink::sendCode()`                          | 5 envíos / 5 min por email+IP   |
| `passkeys`   | `FortifyServiceProvider::configureRateLimiting()` | 30/min por usuario o IP         |

`bootstrap/app.php` aplica `throttleApi()` al grupo `api` (el esqueleto de
Laravel no lo trae) y configura `trustProxies`, sin el cual `$request->ip()`
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
| `PasskeysToggleTest` | el toggle `AUTH_PASSKEYS` añade/quita la feature, sus rutas y su limiter | 9 |
| `PasskeysScreenTest` | acceso a `/user/passkeys`, listado por dueño y revocación | 10 |

Total Auth: **60 tests / 169 assertions**. (Cifra real de
`./vendor/bin/pest app/Modules/Auth --compact`; actualízala cuando cambie.)

## Cómo extender

- **Agregar un proveedor Socialite** (ej. Twitter): añade `SOCIAL_TWITTER` a `config/kore-app.php`, configura `services.twitter` en `config/services.php`, y agrega `'twitter'` al array de proveedores válidos en `Routes/web.php`.
- **Cambiar la UI de auth**: edita las blades en `Resources/views/pages/`. El layout es `Resources/views/layouts/auth.blade.php`.
- **Forzar email verification en una ruta**: middleware `verified` (ya aplicado en `/dashboard`).
- **Cambiar el redirect post-login**: `config/fortify.php` → `'home' => '/dashboard'`.
- **Confirmar la contraseña con una passkey**: las rutas ya existen
  (`passkey.confirm-options` / `passkey.confirm`); basta con llamar a
  `Passkeys.verify({ routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' } })`
  desde `confirm-password.blade.php`.
