# Módulo Users

**TL;DR**: CRUD de usuarios con asignación de rol + permisos directos. Es el primer módulo de negocio que ships con el boilerplate y ejemplifica el patrón completo (Form Object + FormComponent + KoreDataTable + Controller + Policy + permission middleware).

## Estructura

```
app/Modules/Users/
├── Forms/
│   └── UserForm.php                    # Livewire Form Object
├── Http/
│   ├── Controllers/UsersController.php # index/create/edit (devuelven blades)
│   └── Livewire/
│       ├── FormComponent.php           # wraps UserForm + computed props
│       └── TableUsers.php              # KoreDataTable
├── Policies/UserPolicy.php             # autorización (auto-registrada via Gate::policy)
├── Providers/UsersModuleServiceProvider.php
├── Resources/views/
│   ├── pages/                          # index, create, edit (usan x-layouts.app)
│   └── livewire/form-component.blade.php
├── Routes/web.php
└── Tests/Feature/UsersCrudTest.php
```

## Rutas

| Verbo  | URI                       | Nombre          | Permiso requerido   |
|--------|---------------------------|------------------|----------------------|
| GET    | `/users`                  | `users.index`    | `users.view`         |
| GET    | `/users/create`           | `users.create`   | `users.create`       |
| GET    | `/users/{user}/edit`      | `users.edit`     | `users.edit`         |

Las rutas viven en `app/Modules/Users/Routes/web.php` y todas pasan por `auth + verified` y por el middleware `permission:users.{action}`.

> El **save** se ejecuta dentro del componente Livewire (`FormComponent::save()`) — no hay rutas POST/PUT/DELETE explícitas.

## Modelos involucrados

- **`App\Models\User`** (global): trae los traits `HasApiTokens`, `HasFactory`, `HasOneTimePasswords`, `HasRoles`, `Notifiable`, `TwoFactorAuthenticatable` y implementa `MustVerifyEmail`.
- **`App\Modules\Auth\Models\Role`** (constantes + `allRoles()`).
- **`App\Modules\Auth\Models\Module`** (tabla `modules`, fuente de los permisos).

## UserForm — Livewire Form Object

`app/Modules/Users/Forms/UserForm.php` extiende `Livewire\Form` y centraliza:
- Propiedades del usuario + `password_confirmation` + `role` + `permissions[]`
- `rules()` con validación condicional (password requerido sólo al crear; email único ignorando id propio)
- `store()` que ejecuta `User::updateOrCreate` y aplica `syncRoles + syncPermissions`

```php
class UserForm extends Form
{
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public ?string $password = null;
    public ?string $password_confirmation = null;
    public string $role = Role::USER;
    /** @var array<int, string> */
    public array $permissions = [];

    public function rules(): array { /* ... */ }
    public function store(): User { /* updateOrCreate + syncRoles + syncPermissions */ }
}
```

## FormComponent — wraps UserForm

`app/Modules/Users/Http/Livewire/FormComponent.php`:
- `#[Locked] public ?User $model = null` — modelo para edición
- `public UserForm $form` — el form object
- `mount()` rellena el form si hay `model`
- `save()` valida, persiste, dispara toast con `InteractsWithFeedback` y redirige
- Computed properties: `title`, `roles`, `modules` (para el editor de permisos en el blade)
- `#[Layout('layouts.app')]` para envolver con el layout global

La vista (`Resources/views/livewire/form-component.blade.php`) usa Alpine.js para:
- Auto-seleccionar permisos al cambiar el rol (basado en `module.roles` metadata)
- Toggle de todos los permisos de un módulo al click en su nombre

## TableUsers — KoreDataTable

`app/Modules/Users/Http/Livewire/TableUsers.php` extiende `KoreUi\DataTable\KoreDataTable`:

- `query()` excluye superadmins automáticamente con `whereDoesntHave('roles', ...)`.
- Columnas: id, nombre (searchable), email (searchable), rol (BadgeColumn con colores por rol), creado (DateColumn), acciones.
- Filtros: SelectFilter por rol con callback custom (`whereHas('roles', ...)`).
- Acciones por fila: editar, eliminar (con confirm). Eliminar a uno mismo o a un superadmin queda **oculto** automáticamente.
- `confirmDelete($id)` valida y ejecuta el delete; dispara `users-updated`.

## UserPolicy

`app/Modules/Users/Policies/UserPolicy.php`:
- `viewAny`, `view`, `create`, `update` — chequean el permiso correspondiente
- `update` bloquea editar superadmin si quien edita no es superadmin
- `delete` bloquea: borrarse a uno mismo, borrar superadmin, no tener permiso `users.delete`

Registrada via `Gate::policy(User::class, UserPolicy::class)` en el provider.

## Permisos involucrados

Auto-generados por `Module::permissions()` para el slug `users`:

- `users.view`
- `users.create`
- `users.edit`
- `users.delete`

Asignados al rol `Role::ADMIN` en `ModulesSeeder::seedRoles()` (todos los permisos).

## Tests (`app/Modules/Users/Tests/Feature/UsersCrudTest.php`)

10 tests verdes cubren:
- Listado con/sin permiso
- Página create accesible para admin
- Crear usuario via Livewire form (con rol + permisos directos)
- Email duplicado rechazado
- Update sin cambiar password
- Tabla excluye superadmins
- UserForm valida rol contra `assignableNames()`
- UserPolicy bloquea borrarse a uno mismo
- UserPolicy bloquea borrar superadmin

## Cómo extender

- **Agregar campo nuevo al usuario** (ej. `phone`):
  1. Migration que agrega la columna
  2. Súmalo a `User::$fillable`
  3. Súmalo a `UserForm` (propiedad + rule + en `store()`)
  4. Agrégalo a la vista `form-component.blade.php`
  5. Test que cubra el campo
- **Agregar columna a la tabla** (ej. `phone`): agrega un `Column::make('Teléfono', 'phone')->searchable()` en `TableUsers::columns()`.
- **Filtros por más criterios**: agrega un `Filter` en `TableUsers::filters()` (ver `docs/guides/crud.md`).
- **Acciones bulk** (ej. desactivar múltiples): implementa `bulkActions()` y los métodos correspondientes.
