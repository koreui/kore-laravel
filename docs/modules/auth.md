# Módulo Auth

**TL;DR**: Fortify maneja login/register/2FA/email-verify/password-reset con vistas Blade + koreUi (sin Flux UI). Sanctum agrega tokens API (toggle). spatie/laravel-permission da roles. Magic links via OTP. Passkeys (WebAuthn) vía Fortify, toggle `AUTH_PASSKEYS`. Socialite opt-in por proveedor.

## Ubicación

```
app/Modules/Auth/
├── Actions/                     # casos de uso propios (AuthUserRegisterAction, AuthPasskeyDeleteAction,
│                                  InvitationCreateAction, InvitationRedeemAction, InvitationRevokeAction)
├── Console/Commands/            # kore:regenerate-permissions, invitations:prune (toggle)
├── Data/                        # DTOs: RegisterData, DashboardStatData, PasskeyData, InvitationData
├── Enums/                       # (el estado de alta vive en App\Core\Enums\AccountStatus, ver más abajo)
├── Events/                      # frontera pública: ApiTokenIssued, ApiTokenRevoked, AccountActivated
├── Forms/InvitationForm.php     # alta de un código (sólo alta: un código no se edita)
├── Rules/ValidInvitationCode.php
├── Database/
│   ├── Factories/               # ModuleFactory, RoleFactory
│   ├── Migrations/
│   └── Seeders/ModulesSeeder.php
├── Fortify/                     # ADAPTADORES de Fortify: CreateNewUser, ResetUserPassword,
│                                  UpdateUserPassword, UpdateUserProfileInformation,
│                                  PasswordValidationRules
├── Http/
│   ├── Controllers/             # SocialiteController, InvitationsController, AccountController
│   ├── Livewire/                # Dashboard, MagicLink, Passkeys, Invitations/{TableInvitations, FormInvitation}
│   └── Middleware/              # EnsureAccountIsActive (alias `account.active`, toggle)
├── Models/                      # Role, Module (+ ModulesCollection), InvitationCode
├── Policies/                    # PasskeyPolicy (sólo el dueño revoca su credencial), InvitationCodePolicy
├── Providers/
│   ├── AuthModuleServiceProvider.php
│   └── FortifyServiceProvider.php
├── Http/
│   ├── Controllers/Api/V1/UserController.php     # GET /api/v1/user
│   └── Resources/Api/V1/UserMeResource.php       # { id, name, email, roles, permissions }
├── Resources/views/
│   ├── layouts/auth.blade.php
│   ├── livewire/                # dashboard, passkeys, invitations/form-invitation
│   └── pages/                   # login, register, forgot/reset-password, verify-email,
│                                  two-factor-challenge, confirm-password, magic-link,
│                                  account-pending, invitations/{index, create}
├── Routes/
│   ├── web.php                  # magic-link, socialite, /dashboard, /user/passkeys,
│                                  /invitations, /account/pending (toggle)
│   └── api.php                  # /api/v1/user (sólo si API_ENABLED)
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
| GET   | `/invitations`             | `invitations.index`    | `AUTH_INVITATIONS=true` (+ `auth + verified + permission:invitations.manage`) |
| GET   | `/invitations/create`      | `invitations.create`   | `AUTH_INVITATIONS=true` (+ lo mismo) |
| GET   | `/account/pending`         | `account.pending`      | `AUTH_INVITATIONS=true` (+ `auth`, sin `verified`) |
| GET   | `/api/v1/user`             | `api.v1.user.me`       | `API_ENABLED=true` (+ `auth:sanctum`) |

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
- **`AUTH_INVITATIONS`** (default `false`): registro por invitación + estado de
  alta de la cuenta. Ver [Invitaciones y estado de cuenta](#invitaciones-y-estado-de-cuenta).

## Invitaciones y estado de cuenta

**TL;DR**: con `AUTH_INVITATIONS=true`, `/register` exige un código, la cuenta
nace con el rol que dice ese código, y el middleware `account.active` deja fuera
a quien esté `pending` o `suspended`. Con el toggle apagado el registro es
abierto y toda cuenta nace activa, como siempre.

### Qué enciende el toggle

| Pieza | Dónde |
|-------|-------|
| Campo `invitation_code` en `/register` y su validación | `Auth/Resources/views/pages/register.blade.php` + `Auth/Fortify/CreateNewUser` |
| Pantallas `/invitations` y `/invitations/create` (permiso `invitations.manage`) | `Auth/Routes/web.php` → `InvitationsController` + los dos Livewire de `Http/Livewire/Invitations/` |
| Pantalla de espera `/account/pending` | `AccountController@pending` |
| Middleware `account.active` sobre los grupos `web` y `api` | `AuthModuleServiceProvider::registerInvitations()` |
| Panel de estado en `/users/{id}/edit` | `Users\Http\Livewire\AccountStatusPanel` (ver [`users.md`](users.md)) |
| Enlace «Invitaciones» del menú | `resources/views/components/layouts/app.blade.php` |
| Comando `invitations:prune` y su tarea nocturna | `Auth/Console/Commands/InvitationsPruneCommand` + `routes/console.php` |

Y lo que **no** depende del toggle, a propósito:

- **El esquema.** La tabla `invitation_codes` y las columnas `account_status` /
  `activated_at` de `users` se migran siempre. Es la misma regla que la tabla
  `devices` o la `media`: un toggle apaga rutas y comportamiento, nunca la forma
  de la base ([`../architecture/toggles.md`](../architecture/toggles.md)).
- **El permiso `invitations.manage`.** Lo siembra `ModulesSeeder` con el módulo
  `invitations`, encendido o apagado. Si dependiera del toggle, encenderlo en
  producción exigiría acordarse además de volver a sembrar permisos para que
  alguien pudiera entrar a la pantalla, justo cuando ya hay tráfico.

### El código

`App\Modules\Auth\Models\InvitationCode`. Tres decisiones que no son detalles:

- **Se normaliza siempre** (`InvitationCode::normalize()`): mayúsculas y sin
  espacios, ni siquiera los de en medio. Se guarda así y se busca así, porque
  quien lo teclea desde un móvil no debe fallar por un cambio de caja ni por lo
  que se pega al copiarlo de un chat. `«kore 2026»` y `«KORE2026»` son el mismo
  código.
- **No hay booleano `activo`.** Revocar es adelantar `expires_at` a ahora
  (`InvitationRevokeAction`). Un estado menos que mantener, una fecha más que
  auditar, y una sola pregunta —`isUsable()`— en vez de dos que pueden
  contradecirse.
- **`uses` vive en la fila**, no se cuenta con un `hasMany` sobre `users`: así el
  alta puede incrementarlo dentro de su propia transacción, con la fila
  bloqueada, y dos registros simultáneos no se cuelan por encima de `max_uses`.

Un código **no se edita**: `Auth\Forms\InvitationForm` sólo da de alta. Cambiarle
el rol o el cupo después de repartirlo cambiaría el trato con quien ya lo tiene
en la mano; la alternativa —revocar y crear otro— deja el rastro de las dos
decisiones.

### El alta, paso a paso

1. `Auth\Fortify\CreateNewUser` valida `invitation_code` con
   `Auth\Rules\ValidInvitationCode`, que dice **por qué** no sirve (caducado,
   agotado, inexistente).
2. `InvitationRedeemAction` abre una transacción, relee el código con
   `lockForUpdate()` y vuelve a comprobarlo. La validación responde por lo que se
   veía al enviar el formulario; esto responde por lo que hay en el instante de
   escribir, y entre las dos cosas cabe otro registro que agote el cupo.
3. Crea el usuario **activo** (`activated_at` con la fecha), le asigna el rol del
   código, suma un uso y dispara `Auth\Events\AccountActivated`.
4. Si el código ya no sirve lanza `ConflictException` —de dominio, no de Http— y
   el adaptador de Fortify la convierte en un error del campo, no en un 500.

Presentar un código válido **es** la activación: dejar la cuenta en `pending`
obligaría a aprobar a mano a quien ya fue invitado. Lo que sí nace `pending` es
lo que entra por una puerta que no pide código: el **login social**
(`SocialiteController::statusForNewAccount()`), que con el toggle encendido es
justo el hueco que quedaría abierto.

### Estados de la cuenta

`App\Core\Enums\AccountStatus` — y vive en `Core`, no en Auth, porque quien lo
castea es `App\Models\User`, que es global y no importa una sola clase de
`App\Modules\*` (R5). Es la misma razón por la que `SystemRole` está ahí.

| Estado | Qué significa | Qué puede hacer |
|--------|---------------|-----------------|
| `pending` | Registrada por una puerta que no la activa | Sólo sesión, perfil y la pantalla de espera |
| `active` | Puede operar. **Es el default de la columna** | Todo |
| `suspended` | Se le cerró el acceso a mano | Nada: pierde la sesión en la siguiente petición |

Es ortogonal a `email_verified_at`: ese responde «¿este correo es suyo?» y esto
responde «¿la casa le abrió la puerta?».

### El middleware

`Auth\Http\Middleware\EnsureAccountIsActive`, alias `account.active`. Va montado
sobre los **grupos** `web` y `api`, no ruta por ruta: así una pantalla nueva nace
protegida y nadie tiene que acordarse de blindarla. Lo que se enumera dentro es
lo contrario —la lista corta de lo que una cuenta no activa **sí** puede tocar—:
sesión (`login`, `logout`, `register`, `password.*`, `two-factor.*`,
`verification.*`, `magic-link.*`, `socialite.*`, `api.v1.auth.*`), la pantalla de
espera (`account.pending`) y el endpoint de Livewire (`*livewire.update` y
`livewire.*`), que necesitan las pantallas libres de arriba —el magic link, la
confirmación de contraseña, el registro— porque sus llamadas no viajan por la
ruta de la pantalla sino por `/livewire/update`, y donde cada componente
autoriza por su cuenta (R23). La pantalla de espera **no** monta Livewire: usa
el layout de auth y es HTML plano. Van los dos patrones porque en Livewire 4 la
ruta se llama `default-livewire.update` y `livewire.*` no la casa. Una ruta
**sin nombre** se trata como protegida: no se puede clasificar, y ante la duda
cierra.

Qué hace al bloquear:

- **`pending` en el navegador** → redirección a `/account/pending`. La sesión se
  conserva: todavía tiene cosas que hacer —verificar el correo, esperar— y
  echarla al login no adelantaría ninguna.
- **`suspended` en el navegador** → cierra la sesión y devuelve al login con el
  motivo. Mantenerla abierta dejaría a alguien a quien se le cerró el acceso
  navegando por una aplicación que le contesta que no a todo.
- **Cualquiera de los dos en `api/*`** → lanza
  `App\Exceptions\AccountNotActiveException`, que `ApiExceptionRenderer` rinde
  como **403** con `error.code = account_not_active` (R54). Tiene código propio
  —y no `forbidden`— porque el remedio es distinto: ahí se pide otro permiso;
  aquí se espera a que activen la cuenta, o se deja de intentar. El mensaje sí
  viaja, porque «en revisión» y «suspendida» no se resuelven igual.

### Administración

- Permiso **`invitations.manage`**, uno solo para repartir y revocar: son la
  misma decisión vista desde los dos lados, y separarlas produciría el rol que
  puede abrir la puerta y no cerrarla. Lo declara
  `Auth\Models\Module::specialPermissions()` y lo aplica
  `Auth\Policies\InvitationCodePolicy`.
- `GET /invitations` (`TableInvitations`) lista los códigos con sus registros y
  su estado, y revoca desde la fila.
- `GET /invitations/create` (`FormInvitation`) da de alta uno y **no redirige**:
  el código sólo se puede leer ahí, así que la pantalla se queda y lo enseña con
  `<x-kore::clipboard>`. Mandar al listado obligaría a buscarlo entre las filas
  justo cuando hace falta copiarlo.
- El rol que puede llevar un código sale de
  `AuthorizationCatalog::assignableRoleNames()`, que excluye superadmin: un
  código es una credencial que se reparte por mensajería, y el rol con bypass
  total del `Gate::before` no viaja así (R26).

### Mantenimiento

```bash
php artisan invitations:prune --days=90 --dry-run   # el ensayo
php artisan invitations:prune --days=90             # de verdad
```

Borra **sólo** los códigos con `expires_at` anterior al corte. Uno sin caducidad
no se toca aunque esté agotado: agotado no es lo mismo que cerrado —subirle el
cupo lo reabre— y la fila es el rastro de cuánta gente entró por él. Quien ya se
registró conserva su cuenta: aquí no hay cascada hacia `users`. El scheduler lo
corre a las 04:45, detrás del backup de las 02:00.

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
| `api`        | `AppServiceProvider::configureApiRateLimiters()`  | 60/min por usuario o IP          |
| magic link   | `MagicLink::sendCode()`                          | 5 envíos / 5 min por email+IP   |
| `passkeys`   | `FortifyServiceProvider::configureRateLimiting()` | 30/min por usuario o IP         |

`bootstrap/app.php` aplica `throttleApi()` al grupo `api` (el esqueleto de
Laravel no lo trae) y configura `trustProxies`, sin el cual `$request->ip()`
sería siempre la IP interna del contenedor y el limiter por IP no serviría de
nada.

Desde la v2.2.0 los tres limiters de la API (`api`, `api-auth`, `api-uploads`)
se registran en `AppServiceProvider` con las cifras de `config/kore-api.php`, y
no aquí: son parte del contrato de la API y ningún módulo es su dueño —
`api-uploads` no tiene nada que ver con la autenticación. Ver
[`../guides/api.md`](../guides/api.md).

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

`app/Modules/Auth/Routes/api.php` las registra en tres grupos —el público, el
que va con token y el resto del módulo— y el provider lo carga sólo con
`API_ENABLED=true`:

```php
if ((bool) config('kore-app.api.enabled')) {
    $this->loadRoutesFrom("{$base}/Routes/api.php");
}
```

`GET /api/v1/user` (`api.v1.user.me`) fue el primer endpoint del boilerplate que
cumplió el contrato de R54 y sigue sirviendo de plantilla mínima: controller que
extiende `ApiController`, respuesta por `respond()`, representación en
`UserMeResource` y errores por `ApiExceptionRenderer`. Devuelve

```json
{ "data": { "id": 1, "name": "Ada", "email": "ada@…", "roles": ["Administrador"], "permissions": ["users.view", …] } }
```

**Cambio incompatible de la v2.2.0**: la ruta era `GET /api/user` (`api.user`) y
devolvía el modelo Eloquent a pelo. Un derivado que la consuma tiene que mover
el cliente a `/api/v1/user` y leer bajo `data`. Ver
[`../guides/api.md`](../guides/api.md).

### Autenticación por token (v2.2.0)

El flujo completo vive en `AuthTokenController` y está documentado paso a paso,
con `curl`, en [`../guides/api.md`](../guides/api.md#autenticación-por-token).
Aquí sólo el mapa.

| Método | Ruta | Nombre | Middleware |
|--------|------|--------|-----------|
| `POST` | `/api/v1/auth/login` | `api.v1.auth.login` | `api`, `throttle:api-auth` |
| `POST` | `/api/v1/auth/refresh` | `api.v1.auth.refresh` | `api`, `auth:sanctum`, `throttle:api-auth` |
| `POST` | `/api/v1/auth/logout` | `api.v1.auth.logout` | `api`, `auth:sanctum` |
| `POST` | `/api/v1/auth/logout-all` | `api.v1.auth.logout-all` | `api`, `auth:sanctum` |
| `GET` | `/api/v1/auth/me` | `api.v1.auth.me` | `api`, `auth:sanctum` |
| `GET` | `/api/v1/user` | `api.v1.user.me` | `api`, `auth:sanctum` |

Las dos últimas son **el mismo handler**: `api.v1.user.me` existe desde la
v2.2.0 y no se rompe; el alias bajo `auth/` es el que espera encontrar quien
acaba de llamar a `auth/login` y `auth/logout`.

Cuatro decisiones que conviene tener a mano:

1. **Las abilities del token son los permisos efectivos del usuario**
   (`getAllPermissions()`), y `[]` si no tiene ninguno — nunca `['*']`. Se
   comprueban con `abilities:` / `ability:`, aliasados en `bootstrap/app.php`.
2. **La caducidad va en el token**, desde
   `config('kore-api.tokens.expires_minutes')` (30 días por defecto, `null` = sin
   caducidad). `sanctum.expiration` se queda en `null`: es global y retroactiva.
3. **Una cuenta con 2FA confirmado no entra por la API**: 403
   `two_factor_required`. El reto por API llegará como un endpoint propio.
4. **`device_name` es obligatorio** y es el nombre del token. `device_id`,
   `platform` y `app_version` no se guardan: viajan en `ApiTokenIssued`.

#### Eventos

`App\Modules\Auth\Events\{ApiTokenIssued, ApiTokenRevoked}` son la frontera
pública de Auth hacia quien quiera reaccionar a un login por API sin importar
nada más del módulo (R5). `ApiTokenRevoked::$reason` distingue `logout`,
`logout_all`, `refresh` y `permissions_changed`; `$tokenId` a `null` significa
«todos».

#### Listener: revocar al cambiar permisos

`Listeners/RevokeApiTokensOnPermissionChange` escucha los cuatro eventos de
spatie/laravel-permission (`RoleAttachedEvent`, `RoleDetachedEvent`,
`PermissionAttachedEvent`, `PermissionDetachedEvent`) y retira **todos** los
tokens del usuario afectado. Sin él, degradar a alguien en la pantalla de
usuarios le quita el botón del navegador y no le quita nada de la API: las
abilities de un token se congelan al emitirlo.

> ⚠️ Depende de `'events_enabled' => true` en `config/permission.php` (el default
> del paquete es `false`). Con esa clave apagada el listener no corre nunca y
> nadie se entera; `ApiTokenRevocationTest` la comprueba.

Se cablea siempre, también con `API_ENABLED=false`: el toggle decide si hay
rutas, no si los tokens que ya están en la tabla siguen abriendo puertas.

Cambiar los permisos de un **rol** se ignora a propósito: `ModulesSeeder` y
`kore:auth:permissions` los sincronizan en cada despliegue, y reaccionar a eso
echaría a toda la plantilla de sus móviles cada vez que se añade un módulo.

## Tests

`app/Modules/Auth/Tests/Feature/`:

| Archivo | Qué cubre | Tests |
|---------|-----------|-------|
| `LoginTest` | página, login válido, login inválido, logout | 4 |
| `RegisterTest` | página, registro exitoso, email duplicado | 3 |
| `AuthUserRegisterActionTest` | `AuthUserRegisterAction`: crea, hashea y dispara el evento | 3 |
| `PasswordResetTest` | página, envío de notificación de reset | 2 |
| `ApiTokenTest` | `/api/v1/user`: envelope, roles y permisos, lista blanca de atributos, 401 del invitado y que la ruta vieja ya no existe | 5 |
| `ApiAuthTest` | login (envelope, abilities, caducidad, 2FA, anti-enumeración, throttle), logout, logout-all, refresco y los eventos | 21 |
| `ApiTokenRevocationTest` | el listener de spatie: rol y permiso, `events_enabled`, el evento, aislamiento entre usuarios y lo que NO cubre | 11 |
| `ApiRateLimitTest` | `throttle:api` en el grupo, limiter `api` registrado y cabeceras en la ruta del módulo | 3 |
| `MagicLinkTest` | envío, rate limit, anti-enumeración, login con código | 6 |
| `AuthorizationSeederTest` | módulos, permisos y roles que siembra `ModulesSeeder`, y que el catálogo resuelve el guard también bajo `sanctum` | 8 |
| `TwoFactorToggleTest` | el toggle `AUTH_2FA_ENABLED` añade/quita la feature y sus rutas | 5 |
| `DashboardTest` | el componente Livewire `Dashboard` y sus DTOs de cifras | 3 |
| `FactoryResolutionTest` | los modelos del módulo resuelven su factory dentro del módulo | 4 |
| `PasskeysToggleTest` | el toggle `AUTH_PASSKEYS` añade/quita la feature, sus rutas y su limiter | 9 |
| `PasskeysScreenTest` | acceso a `/user/passkeys`, listado por dueño y revocación | 10 |
| `DevAccountSwitcherTest` | el switcher `/dev/switch-account` sólo existe en `local` y sólo entra en cuentas de dominios reservados | 16 |
| `InvitationsToggleTest` | `AUTH_INVITATIONS`: rutas, middleware sobre los grupos, comando, scheduler y campo del registro; y que el esquema se migra igual con el toggle apagado | 10 |
| `InvitationRegistrationTest` | registro con código válido, normalizado, ausente, desconocido, caducado, agotado y con el cupo lleno | 7 |
| `InvitationActionsTest` | las tres Actions (crear, revocar, canjear) y la normalización del código | 6 |
| `AccountStatusMiddlewareTest` | `EnsureAccountIsActive`: activo pasa, pendiente redirige, suspendido pierde la sesión, API 403 con `account_not_active`, y nada con el toggle apagado | 6 |
| `InvitationsScreenTest` | las dos pantallas: acceso por permiso, alta, rol no asignable, revocación y R23 vía `/livewire/update` | 7 |
| `InvitationsPruneCommandTest` | qué borra `invitations:prune` y que `--dry-run` no borra nada | 2 |

Total Auth: **151 tests / 488 assertions**. (Cifra real de
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
