# Autorización — roles, permisos y módulos

**TL;DR**: spatie/laravel-permission (sin teams) + un modelo `Module` propio que es source of truth de los módulos del sistema y auto-genera permisos en formato `{slug}.{action}`. Cada usuario tiene **un rol + permisos directos**. Los permisos directos permiten personalización individual sin tener que crear un rol nuevo por cada combinación.

## Componentes

```
app/Modules/Auth/
├── Models/
│   ├── Role.php                          # extiende Spatie + constantes
│   ├── Module.php                        # tabla modules + accessor permissions
│   └── Collections/ModulesCollection.php # flatPermissions(), permissionsToArray()
├── Database/
│   ├── Migrations/{create_modules_table}.php
│   └── Seeders/ModulesSeeder.php         # source of truth
└── Console/Commands/
    └── RegeneratePermissionsCommand.php  # `php artisan kore:regenerate-permissions`
```

## Formato de permisos

```
{slug}.{accion}
```

Ejemplos: `users.view`, `users.create`, `users.edit`, `users.delete`, `dashboard.view`.

Por default cada `Module` genera 4 permisos (view/create/edit/delete). Los módulos con permisos **no-CRUD** se declaran en `Module::specialPermissions()`:

```php
private function specialPermissions(): array
{
    return [
        'dashboard' => [
            ['value' => 'dashboard.view', 'label' => 'Ver Dashboard'],
        ],
        // 'mi-modulo' => [...] — agrega aquí los que necesiten permisos custom
    ];
}
```

## Roles que vienen con el boilerplate

| Constante                     | Valor en BD       | Acceso                              |
|-------------------------------|-------------------|-------------------------------------|
| `Role::SUPERADMIN`            | `'superadmin'`    | Bypass total via `Gate::before`. Sólo se asigna por consola; los usuarios con este rol están ocultos del listado UI. |
| `Role::ADMIN`                 | `'Administrador'` | Todos los permisos.                 |
| `Role::USER`                  | `'Usuario'`       | Sólo `dashboard.view` por default.  |

**Usa siempre las constantes**, nunca strings literales:

```php
use App\Modules\Auth\Models\Role;

$user->assignRole(Role::ADMIN);
User::role(Role::USER)->get();
```

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
Route::middleware(['web', 'auth', 'verified'])->prefix('users')->group(function () {
    Route::middleware('permission:users.view')->get('/', 'index')->name('users.index');
    Route::middleware('permission:users.create')->get('/create', 'create');
    Route::middleware('permission:users.edit')->get('/{user}/edit', 'edit');
});
```

Los aliases `permission`, `role` y `role_or_permission` están registrados en `bootstrap/app.php`.

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
