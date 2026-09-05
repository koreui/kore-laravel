# Autorización — roles, permisos y módulos

**TL;DR**: spatie/laravel-permission (sin teams) + un modelo `Module` propio que es source of truth de los módulos del sistema y auto-genera permisos en formato `{slug}.{action}`. Cada usuario tiene **un rol + permisos directos**. Los permisos directos permiten personalización individual sin tener que crear un rol nuevo por cada combinación. En la API los mismos permisos viajan como **abilities** del token, y la Policy sigue siendo el único punto de decisión (R25): ver [Proteger la API](#proteger-la-api-abilities--permisos).

## Componentes

```
app/Core/
├── Enums/SystemRole.php                  # los roles del sistema, visibles para todos
├── Contracts/AuthorizationCatalog.php    # frontera: qué roles/permisos hay
└── Data/Authorization/                   # RoleOptionData, PermissionOptionData,
                                          # PermissionModuleData

app/Modules/Auth/
├── Models/
│   ├── Role.php                          # extiende Spatie + constantes (alias del enum)
│   ├── Module.php                        # tabla modules + accessor permissions
│   └── Collections/ModulesCollection.php # flatPermissions(), permissionsToArray()
├── Support/AuthorizationCatalog.php      # implementación del contrato
├── Database/
│   ├── Factories/{RoleFactory, ModuleFactory}.php
│   ├── Migrations/{create_modules_table}.php
│   └── Seeders/ModulesSeeder.php         # source of truth
└── Console/Commands/
    └── RegeneratePermissionsCommand.php  # `php artisan kore:regenerate-permissions`

app/Modules/Users/
└── Rules/{GrantableRole, GrantablePermission}.php   # anti-escalada de privilegios
```

## Formato de permisos

```
{slug}.{accion}
```

Ejemplos: `users.view`, `users.create`, `users.edit`, `users.delete`,
`dashboard.view`, `invitations.manage`, `webhooks.manage`.

Por default cada `Module` genera 4 permisos (view/create/edit/delete). Los módulos con permisos **no-CRUD** se declaran en `Module::specialPermissions()`:

```php
private function specialPermissions(): array
{
    return [
        'dashboard' => [
            ['value' => 'dashboard.view', 'label' => 'Ver Dashboard'],
        ],
        'invitations' => [
            ['value' => 'invitations.manage', 'label' => 'Gestionar Invitaciones'],
        ],
        'webhooks' => [
            ['value' => 'webhooks.manage', 'label' => 'Gestionar webhooks'],
        ],
        // 'mi-modulo' => [...] — agrega aquí los que necesiten permisos custom
    ];
}
```

### `invitations.manage`

Uno solo para repartir **y** revocar códigos de invitación, y es deliberado: son
la misma decisión vista desde los dos lados, y separarlas produciría el rol que
puede abrir la puerta y no cerrarla. Lo aplica
`Auth\Policies\InvitationCodePolicy` (`viewAny`, `create`, `delete`) y el
middleware `permission:invitations.manage` de `/invitations*`.

El módulo `invitations` se siembra **siempre**, también con `AUTH_INVITATIONS`
apagado: el catálogo de permisos es forma, no capacidad —el mismo criterio que
las tablas de un módulo apagado—. Desde la v2.4.0 lo fija **R60**, y lo verifica
`kore:arch:check`: ni `ModulesSeeder` ni `Module::specialPermissions()` pueden
leer `config('kore-app.…')` ni `env()`. Si dependiera del toggle, encenderlo en
producción exigiría además acordarse de volver a sembrar permisos para que
alguien pudiera entrar a la pantalla, justo cuando ya hay tráfico. Lo que el
toggle apaga es la pantalla, no quién podría verla.
Ver [`../modules/auth.md`](../modules/auth.md#invitaciones-y-estado-de-cuenta).

**`webhooks.manage` es un permiso y no cuatro**, y conviene entender por qué
antes de copiar el patrón: ver la lista de endpoints ya enseña a qué sistemas se
les está contando lo que pasa dentro de la instalación, y quien la ve puede leer
el payload de cada entrega. No hay un «sólo lectura» que sea menos sensible que
el resto: o se administra la integración, o no se entra. Lo lleva el rol
Administrador (y el superadmin por su `Gate::before`). El módulo se siembra
siempre, también con `WEBHOOKS_ENABLED=false`: un toggle apaga rutas y
comportamiento, no el catálogo de permisos. Ver
[`../modules/webhooks.md`](../modules/webhooks.md).

## Roles que vienen con el boilerplate

Los valores viven en el enum `App\Core\Enums\SystemRole`, en **Core** y no en el
módulo Auth: así cualquier módulo puede comparar contra un rol sin importar
`App\Modules\Auth\*` (regla 3 de CLAUDE.md).

| Enum                     | Constante equivalente | Valor en BD       | Acceso                              |
|--------------------------|-----------------------|-------------------|-------------------------------------|
| `SystemRole::Superadmin` | `Role::SUPERADMIN`    | `'superadmin'`    | Bypass total via `Gate::before`. Sólo se asigna por consola; los usuarios con este rol están ocultos del listado UI. |
| `SystemRole::Admin`      | `Role::ADMIN`         | `'Administrador'` | Todos los permisos.                 |
| `SystemRole::User`       | `Role::USER`          | `'Usuario'`       | Sólo `dashboard.view` por default.  |

`Role::SUPERADMIN`, `Role::ADMIN` y `Role::USER` siguen existiendo: se definen a
partir del enum (`public const string ADMIN = SystemRole::Admin->value;`), así
que un proyecto derivado no tiene que tocar nada. `Role::allRoles()` y
`Role::assignableNames()` también se construyen desde el enum.

**Nunca uses strings literales.** Desde otro módulo, el enum:

```php
use App\Core\Enums\SystemRole;

$user->hasRole(SystemRole::Superadmin->value);
```

Dentro del módulo Auth (seeders, modelos), las constantes:

```php
use App\Modules\Auth\Models\Role;

$user->assignRole(Role::ADMIN);
User::role(Role::USER)->get();
```

Para añadir un rol: suma el `case` a `SystemRole`, dale su etiqueta en `label()`
y crea su `syncPermissions` en `ModulesSeeder`.

## `AuthorizationCatalog` — la frontera entre módulos

`Role` y `Module` son del módulo Auth. Los demás módulos (Users, y los que
vengan) no los importan: piden el catálogo por el contrato de Core.

```php
namespace App\Core\Contracts;

interface AuthorizationCatalog
{
    /** @return array<int, RoleOptionData> */
    public function assignableRoles(): array;

    /** @return array<int, string> */
    public function assignableRoleNames(): array;

    /** @return array<int, PermissionModuleData> */
    public function permissionModules(): array;

    /** @return array<int, string> */
    public function permissionsForRole(string $role): array;
}
```

La implementación es `App\Modules\Auth\Support\AuthorizationCatalog` y se bindea
en `AuthModuleServiceProvider::register()`:

```php
$this->app->singleton(AuthorizationCatalogContract::class, AuthorizationCatalog::class);
```

Uso típico (el formulario de usuarios):

```php
#[Computed]
public function modules(): array
{
    return array_map(
        fn (PermissionModuleData $module): array => $module->toArray(),
        resolve(AuthorizationCatalog::class)->permissionModules(),
    );
}
```

Los DTOs se serializan exactamente a la estructura que ya consumían el
`<x-kore::select>` y el Alpine de la matriz de permisos
(`{module, permissions: [{value,label}], roles}`), así que las vistas no
cambiaron.

## Anti-escalada de privilegios

Tener `users.create` + `users.edit` no puede convertirse en «tener todo»: sin
más reglas, cualquier editor podía crear una cuenta con permisos que él no
tiene (o con rol `Administrador`) y entrar con ella.

`UserForm::rules()` añade dos reglas propias, en `app/Modules/Users/Rules/`:

| Regla                 | Qué exige                                                                 |
|-----------------------|---------------------------------------------------------------------------|
| `GrantablePermission` | El actor sólo concede permisos que él mismo tiene.                        |
| `GrantableRole`       | El actor sólo asigna un rol si posee **todos** los permisos de ese rol.   |

Detalles de diseño:

- El actor se pasa **por constructor**; dentro de la regla no se lee `auth()`.
  Así se pueden testear y reutilizar desde consola.
- `GrantableRole` se mide en **permisos, no en nombres de rol**: un rol nuevo
  inventado por un proyecto derivado queda cubierto solo, y un rol sin permisos
  se puede asignar sin ser administrador.
- El **superadmin las salta** (su `Gate::before` ya le da todo).
- La matriz de permisos de la vista no cambia: un editor sigue viendo todos los
  permisos del sistema aunque no pueda concederlos; si lo intenta, la validación
  responde con el permiso concreto en el mensaje. Ocultarlos en el cliente es
  cosmética pendiente, no seguridad.

Tests: `app/Modules/Users/Tests/Feature/PrivilegeEscalationTest.php`.

## Source of truth: `ModulesSeeder`

```php
class ModulesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedModules();        // tabla modules
        $this->seedPermissions();    // tabla permissions (auto desde flatPermissions)
        $this->seedRoles();          // tabla roles + role_has_permissions
    }
}
```

**Para agregar un módulo nuevo:**

1. Súmalo al array `$modules` de `seedModules()`:
   ```php
   [
       'name'   => 'Productos',
       'slug'   => 'productos',
       'roles'  => [Role::ADMIN, Role::USER],   // sólo metadata UI (preselección)
       'active' => true,
   ],
   ```
2. Si tiene permisos no-CRUD, añadelos a `Module::specialPermissions()`.
3. Regenera y sincroniza:
   ```bash
   php artisan kore:regenerate-permissions
   ```
   Esto re-corre el seeder y sincroniza todos los permisos a los usuarios con rol `Role::ADMIN`.

> El campo `roles` del Module es **metadata UI**: lo usa el formulario de usuarios (Alpine) para auto-seleccionar permisos al elegir un rol. NO afecta la lógica de Spatie.

## Proteger rutas

```php
use App\Modules\Users\Http\Controllers\UsersController;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('users')
    ->as('users.')
    ->controller(UsersController::class)   // ← sin esto, 'index' no es una acción válida
    ->group(function (): void {
        Route::middleware('permission:users.view')->get('/', 'index')->name('index');
        Route::middleware('permission:users.create')->get('/create', 'create')->name('create');
        Route::middleware('permission:users.edit')->get('/{user}/edit', 'edit')->name('edit');
    });
```

Es `app/Modules/Users/Routes/web.php` tal cual. Dos detalles que no son opcionales:

- **`->controller(...)`**: con la acción escrita como cadena suelta (`'index'`),
  Laravel necesita saber de qué controller es. Sin esa llamada la ruta no
  resuelve.
- **`->as('users.')`**: prefija los nombres, así que dentro del grupo se escribe
  `->name('index')` y sale `users.index`. Poner `->name('users.index')` con el
  `as()` puesto daría `users.users.index`.

Los aliases `permission`, `role` y `role_or_permission` están registrados en `bootstrap/app.php`.

## Proteger la API: abilities = permisos

Un token de Sanctum lleva una lista de **abilities**, y en este boilerplate esa
lista son los **permisos efectivos de su dueño en el momento de emitirlo**:

```php
// AuthApiTokenIssueAction
$abilities = $user->getAllPermissions()->pluck('name')->all();  // roles + directos
$user->createToken($device->name, $abilities, $expiresAt);
```

Si el usuario no tiene ninguno, el token sale con `[]` — **nunca** con `['*']`.
El fallback al comodín es tentador y es el error: le da la llave maestra justo a
la cuenta que no tiene ninguna puerta.

Se comprueban con dos middleware de Sanctum, aliasados en `bootstrap/app.php`
(el paquete trae las clases pero no los alias):

| Alias | Clase | Semántica |
|-------|-------|-----------|
| `abilities:a,b` | `CheckAbilities` | **todas** (AND) |
| `ability:a,b` | `CheckForAnyAbility` | **al menos una** (OR) |

```php
// app/Modules/Users/Routes/api.php
Route::middleware('abilities:users.edit')->match(['put', 'patch'], '/users/{user}', 'update');
```

`abilities:users.edit` se lee igual que el `permission:users.edit` de la ruta web
equivalente.

### La ability no sustituye a la Policy (R25)

La Policy sigue siendo el único punto de decisión, y el controller de la API la
consulta igual que lo hace un componente Livewire:

```php
public function update(UserUpdateRequest $request, User $user, UserUpdateAction $update): JsonResponse
{
    $this->authorize('update', $user);   // ← la decisión, aquí
    // …
}
```

Son dos preguntas distintas, y por eso van las dos:

| | Qué contesta | De qué depende |
|---|---|---|
| **Ability** | qué se le concedió a *este token* al emitirlo | del token |
| **Policy** | qué puede *este usuario* ahora sobre *este registro* | del usuario y del registro |

Una ability no puede expresar «sólo un superadmin edita a otro superadmin»:
depende del registro, no del token. Y una Policy no puede expresar «este token
concreto es de sólo lectura»: no sabe con qué credencial llegó la petición.
Quitar cualquiera de las dos deja un agujero distinto.

### Las abilities caducan; los permisos, no

La lista se congela al emitir el token. Por eso `RevokeApiTokensOnPermissionChange`
retira **todos** los tokens de quien acaba de cambiar de rol o de permisos: sin
él, degradar a alguien en la pantalla de usuarios le quita el botón del navegador
y no le quita nada de la API. Ver
[`../guides/api.md`](../guides/api.md#revocación-al-cambiar-permisos).

> ⚠️ Depende de `'events_enabled' => true` en `config/permission.php` (el default
> del paquete es `false`).

### El guard bajo el que se buscan los roles

`AuthorizationCatalog::permissionsForRole()` resuelve el guard con
`Guard::getDefaultName(User::class)` y **no** con `config('auth.defaults.guard')`.
No es un detalle de estilo:

> **Cicatriz (v2.2.0).** `AuthManager::shouldUse()` —lo que llama `auth:sanctum`,
> y también `Sanctum::actingAs()` en un test— **escribe**
> `config(['auth.defaults.guard' => 'sanctum'])`. Los roles se siembran con
> `guard_name = 'web'`, así que en toda petición de la API el método devolvía
> `[]`, `GrantableRole` no encontraba ningún permiso «que el actor no tenga» y
> dejaba pasar cualquier rol: un `users.create` podía crear administradores. R26
> quedaba desactivada en silencio justo en el canal donde no hay una pantalla que
> lo delate. `AuthorizationSeederTest` lo comprueba ahora bajo los dos guards.

La regla práctica: para preguntar «¿bajo qué guard viven los roles de este
modelo?» se usa `Guard::getDefaultName($modelo)`, que es lo que hace spatie por
dentro. `config('auth.defaults.guard')` contesta otra pregunta —«¿qué guard está
usando la aplicación ahora mismo?»— y cambia bajo los pies.

## Proteger en Blade

```blade
@can('users.view')
    <a href="{{ route('users.index') }}">Usuarios</a>
@endcan

@can('update', $user)
    <button>Editar</button>
@endcan
```

## Proteger en Livewire

```php
$this->authorize('users.edit');                // por nombre de permiso
Gate::authorize('update', $user);              // por policy
```

## Bypass de superadmin

`AuthModuleServiceProvider::registerSuperadminGate()` registra:

```php
Gate::before(function ($user, $ability) {
    if ($user instanceof User && $user->hasRole(Role::SUPERADMIN)) {
        return true;
    }
    return null;
});
```

Cualquier `@can` o `authorize()` retorna `true` automáticamente para superadmins. El rol se asigna **sólo por consola** (no via UI):

```bash
php artisan tinker
> User::find(1)->assignRole('superadmin');
```

## Asignar rol + permisos directos

El patrón del boilerplate combina ambos para permitir **personalización individual** sin crear un rol nuevo por cada combinación posible:

```php
$user->syncRoles([Role::USER]);                    // 1 rol
$user->syncPermissions(['users.view', 'users.create']);  // permisos extras directos
```

Verificación:

```php
$user->hasRole(Role::USER);                  // true
$user->hasPermissionTo('users.view');        // true (directo)
$user->getDirectPermissions();               // sólo los directos
$user->getAllPermissions();                  // rol + directos combinados
```

## Cheatsheet de Spatie

```php
// Asignar
$user->assignRole(Role::ADMIN);
$user->syncRoles([Role::USER]);
$user->givePermissionTo('users.view');
$user->syncPermissions([...]);

// Verificar
$user->hasRole(Role::ADMIN);
$user->can('users.view');
$user->hasPermissionTo('users.view');

// Query
User::role(Role::ADMIN)->get();
User::permission('users.view')->get();
```

## Factories

`Role` y `Module` tienen `HasFactory` y sus factories viven **dentro del
módulo**, en `app/Modules/Auth/Database/Factories/`. Lo hace posible el resolver
que registra `AppServiceProvider::configureFactories()`:

```
App\Modules\{Mod}\Models\{X} → App\Modules\{Mod}\Database\Factories\{X}Factory
```

El resto de modelos (`App\Models\User`) siguen en `database/factories/`.

```php
Module::factory()->create();                       // módulo activo con 4 permisos
Module::factory()->inactive()->create();
Role::factory()->create();                         // rol ad-hoc para un test
Role::factory()->system(SystemRole::Admin)->create();
```

Los roles del sistema NO se crean con la factory: los siembra `ModulesSeeder`.

## Tests

`app/Modules/Auth/Tests/Feature/AuthorizationSeederTest.php`:
- Seeder crea modules, permisos y roles correctamente.
- Admin obtiene todos los permisos.
- User obtiene sólo `dashboard.view`.
- Superadmin pasa el `Gate::before` aún para permisos no registrados.
- `kore:regenerate-permissions` sincroniza permisos a admins.
- `Module` genera CRUD permisos por default.
- `ModulesCollection::flatPermissions()` devuelve la lista plana.

## Recursos

- spatie/laravel-permission: https://spatie.be/docs/laravel-permission/v6/introduction

## Nota Laravel 13 · `#[Middleware]` y `#[Authorize]`

Laravel 13 permite declarar middleware y autorización como atributos del
controller (`Illuminate\Routing\Attributes\Controllers\{Middleware, Authorize}`).
El boilerplate **no los usa**, por dos razones:

- No valen para Livewire: los componentes no son controllers y `/livewire/update`
  no pasa por el middleware de la ruta. R23 sigue siendo la regla: la
  autorización se decide **dentro** del componente.
- En los controllers de rutas (`SocialiteController`, `DocsController`), la
  política de acceso vive en `Routes/web.php`, donde se lee de un vistazo quién
  entra a qué. Moverla a atributos repartiría esa lectura por varios archivos.

Si un derivado los adopta para controllers puros, que sea en todos o en ninguno.
