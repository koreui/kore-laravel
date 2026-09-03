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

> El **save** y el **delete** se ejecutan dentro de componentes Livewire
> (`FormComponent::save()`, `TableUsers::confirmDelete()`) — no hay rutas
> POST/PUT/DELETE explícitas.

> ⚠️ **El middleware `permission:*` de las rutas NO protege las acciones
> Livewire.** Esas peticiones van a `/livewire/update`, que sólo pasa por el
> grupo `web`. Toda autorización real vive **dentro** del componente
> (`$this->authorize(...)`). Ver [Autorización](#autorización).

## Modelos involucrados

- **`App\Models\User`** (global): trae los traits `HasApiTokens`, `HasFactory`, `HasOneTimePasswords`, `HasRoles`, `Notifiable`, `TwoFactorAuthenticatable` y implementa `MustVerifyEmail`.
- **`App\Modules\Auth\Models\Role`** (constantes + `allRoles()`).
- **`App\Modules\Auth\Models\Module`** (tabla `modules`, fuente de los permisos).

## UserForm — Livewire Form Object

`app/Modules/Users/Forms/UserForm.php` extiende `Livewire\Form` y centraliza:
- Propiedades del usuario + `password_confirmation` + `role` + `permissions[]`
- `rules()` con validación condicional (password requerido sólo al crear; email único ignorando id propio)
- `store()` que resuelve el modelo (`findOrFail` si hay `id`, `new User` si no),
  hace `fill(...)->save()` y aplica `syncRoles + syncPermissions`

```php
class UserForm extends Form
{
    #[Locked]                 // ← imprescindible, ver más abajo
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public ?string $password = null;
    public ?string $password_confirmation = null;
    public string $role = Role::USER;
    /** @var array<int, string> */
    public array $permissions = [];

    public function rules(): array { /* ... */ }
    public function store(): User { /* findOrFail|new + fill + syncRoles + syncPermissions */ }
}
```

> **Por qué `#[Locked]` en `$id`**: sin el candado, cualquiera con permiso
> `users.create` podía mandar `form.id` por `/livewire/update` y hacer que el
> guardado sobrescribiera a **otro usuario** (email, password, rol y permisos).
> `#[Locked]` sólo bloquea escrituras del cliente: `mount()` sigue pudiendo
> asignarlo del lado servidor con `fill()`.
>
> `store()` tampoco usa ya `updateOrCreate(['id' => $this->id], ...)`: además de
> ser el vector del bug, ese patrón es incompatible con la protección de mass
> assignment (el `id` no es `fillable` y `Model::unguard()` global ya no existe).

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
- Acciones por fila: editar, eliminar (con confirm). Eliminar a uno mismo o a un superadmin queda **oculto** automáticamente — pero eso es sólo cosmética.
- `confirmDelete($id)` hace `abort_if($user->id === auth()->id(), 403)` y
  `$this->authorize('delete', $user)` **antes** de borrar; luego dispara
  `users-updated`.

## Autorización

Tres capas, y las tres hacen falta:

| Capa                       | Qué cubre                                    | Qué NO cubre                        |
|----------------------------|----------------------------------------------|-------------------------------------|
| `permission:*` en rutas    | la navegación GET a `/users`, `/users/create` | nada de lo que pase por `/livewire/update` |
| `authorize()` en componentes | `FormComponent::mount/save`, `TableUsers::confirmDelete` | —                          |
| `->hidden()` en RowActions | esconder botones                              | **no autoriza nada**                |

Puntos exactos donde se autoriza:

- `FormComponent::mount()` → `create` (alta) / `update` (edición)
- `FormComponent::save()` → `create` o `update`, **antes** de validar y escribir
- `TableUsers::confirmDelete()` → guarda de auto-borrado + `delete`

### UserPolicy

`app/Modules/Users/Policies/UserPolicy.php`:
- `viewAny`, `view`, `create`, `update` — chequean el permiso correspondiente
- `update` bloquea editar superadmin si quien edita no es superadmin
- `delete` bloquea: borrarse a uno mismo, borrar superadmin, no tener permiso `users.delete`

Registrada via `Gate::policy(User::class, UserPolicy::class)` en el provider.

> **Ojo con el `Gate::before` del superadmin** (`AuthModuleServiceProvider`):
> devuelve `true` antes de consultar la policy, así que para ese rol la policy
> **no se evalúa**. Por eso la guarda de «no borrarse a uno mismo» se repite
> como `abort_if` dentro de `TableUsers::confirmDelete()`.

## Permisos involucrados

Auto-generados por `Module::permissions()` para el slug `users`:

- `users.view`
- `users.create`
- `users.edit`
- `users.delete`

Asignados al rol `Role::ADMIN` en `ModulesSeeder::seedRoles()` (todos los permisos).

## Auditoría

`App\Models\User` usa `Spatie\Activitylog\Models\Concerns\LogsActivity` y
registra en `activity_log` los cambios de `name` y `email` (nunca el password),
con el `causer` autenticado. Ver [`../ops/observability.md`](../ops/observability.md).

## Tests

`app/Modules/Users/Tests/Feature/`:

**`UsersCrudTest.php`** — el CRUD feliz:
- Listado con/sin permiso
- Página create accesible para admin
- Crear usuario via Livewire form (con rol + permisos directos)
- Email duplicado rechazado
- Update sin cambiar password
- Tabla excluye superadmins
- UserForm valida rol contra `assignableNames()`
- UserPolicy bloquea borrarse a uno mismo / borrar superadmin

**`UsersAuthorizationTest.php`** — ataca los componentes directamente, como lo
haría un cliente malicioso por `/livewire/update`:
- `confirmDelete` sin `users.delete` → 403 y el usuario sigue existiendo
- No se puede borrar al superadmin ni a uno mismo (ni siendo superadmin)
- El superadmin sí borra a un usuario normal
- `set('form.id', $otro)` lanza `CannotUpdateLockedPropertyException`
- Montar el formulario en modo edición sin `users.edit` → 403
- Perder el permiso entre `mount()` y `save()` → 403

**`UserActivityLogTest.php`** — el audit log registra alta, cambios y `causer`,
y nunca el password.

## Cómo extender

- **Agregar campo nuevo al usuario** (ej. `phone`):
  1. Migration que agrega la columna
  2. Súmalo a `User::$fillable` (obligatorio: ya no hay `Model::unguard()` global)
  3. Súmalo a `UserForm` (propiedad + rule + en `store()`)
  4. Agrégalo a la vista `form-component.blade.php`
  5. Test que cubra el campo
- **Agregar columna a la tabla** (ej. `phone`): agrega un `Column::make('Teléfono', 'phone')->searchable()` en `TableUsers::columns()`.
- **Filtros por más criterios**: agrega un `Filter` en `TableUsers::filters()` (ver `docs/guides/crud.md`).
- **Acciones bulk** (ej. desactivar múltiples): implementa `bulkActions()` y los métodos correspondientes.
