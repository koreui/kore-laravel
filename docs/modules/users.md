# Módulo Users

**TL;DR**: CRUD de usuarios con asignación de rol + permisos directos. Es el
módulo de referencia del boilerplate y ejemplifica el patrón completo: Form
Object (validación) + `UserData` (DTO) + Actions (escritura) + Events +
FormComponent + KoreDataTable + Controller + Policy + `permission` middleware +
reglas anti-escalada.

## Estructura

```
app/Modules/Users/
├── Actions/                            # los casos de uso (escriben)
│   ├── UserCreateAction.php
│   ├── UserUpdateAction.php
│   └── UserDeleteAction.php
├── Data/UserData.php                   # DTO de entrada de las Actions
├── Events/                             # canal hacia otros módulos
│   ├── UserCreated.php
│   ├── UserUpdated.php
│   └── UserDeleted.php
├── Forms/
│   └── UserForm.php                    # Livewire Form Object (valida + toData)
├── Http/
│   ├── Controllers/UsersController.php # index/create/edit (devuelven blades)
│   └── Livewire/
│       ├── FormComponent.php           # autoriza → valida → DTO → Action
│       └── TableUsers.php              # KoreDataTable
├── Policies/UserPolicy.php             # autorización (auto-registrada via Gate::policy)
├── Rules/                              # anti-escalada de privilegios
│   ├── GrantablePermission.php
│   └── GrantableRole.php
├── Providers/UsersModuleServiceProvider.php
├── Resources/
│   ├── lang/en.json                    # traducción al inglés (español es la fuente, R33)
│   └── views/
│       ├── pages/                      # index, create, edit (usan x-layouts.app)
│       └── livewire/form-component.blade.php
├── Routes/web.php
└── Tests/Feature/
    ├── UserCreateActionTest.php        # una clase por Action…
    ├── UserUpdateActionTest.php
    ├── UserDeleteActionTest.php
    ├── UsersCrudTest.php               # …y el flujo completo por HTTP + Livewire
    ├── UsersAuthorizationTest.php      # permisos por rol en rutas y componentes
    ├── PrivilegeEscalationTest.php     # R26: nadie concede lo que no tiene
    └── UserActivityLogTest.php         # el audit log de spatie
```

El módulo **no importa una sola clase de `App\Modules\Auth`** (arch test que lo
vigila). Lo que necesita de la autorización lo pide por
`App\Core\Contracts\AuthorizationCatalog` y `App\Core\Enums\SystemRole`.

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

## Modelos y dependencias

- **`App\Models\User`** (global): trae los traits `HasApiTokens`, `HasFactory`, `HasOneTimePasswords`, `HasRoles`, `Notifiable`, `TwoFactorAuthenticatable` y implementa `MustVerifyEmail`.
- **`App\Core\Enums\SystemRole`**: los roles del sistema (`Superadmin`, `Admin`, `User`).
- **`App\Core\Contracts\AuthorizationCatalog`**: roles asignables y matriz de
  permisos. Lo implementa el módulo Auth sobre sus modelos `Role` y `Module`;
  Users sólo conoce el contrato. Ver [Autorización](../architecture/authorization.md).

## Actions — dónde se escribe

`app/Modules/Users/Actions/`, `final`, extienden `App\Core\Actions\Action`, un
único método público `handle()`:

| Action              | Firma                                          | Hace                                                                 |
|---------------------|------------------------------------------------|----------------------------------------------------------------------|
| `UserCreateAction`  | `handle(UserData $data): User`                 | crea (password hasheado, `email_verified_at = now()`), `syncRoles`, `syncPermissions`, dispara `UserCreated` |
| `UserUpdateAction`  | `handle(User $user, UserData $data): User`     | actualiza (password sólo si viene), sincroniza, dispara `UserUpdated` |
| `UserDeleteAction`  | `handle(User $user): void`                     | borra y dispara `UserDeleted`                                        |

Ninguna lee `auth()`, `request()` ni `session()`: la autorización es del
llamante, así que sirven igual desde un comando artisan o un job. Las dos que
escriben varias tablas envuelven el bloque en `DB::transaction()` (un usuario
sin rol es un registro a medias) y disparan el evento **fuera** de la
transacción.

## UserData — el DTO

`app/Modules/Users/Data/UserData.php`, `final`, extiende `App\Core\Data\Data`:
`name`, `email`, `?password`, `role`, `permissions[]`. Sin lógica. `password` a
`null` significa «no la cambies».

## Events

`UserCreated`, `UserUpdated`, `UserDeleted` (`final readonly`, con el `User` como
propiedad pública) son **el canal oficial** para que otro módulo reaccione sin
importar Users. Hoy no hay listeners: existen para que añadir uno no obligue a
tocar este módulo.

## UserForm — Livewire Form Object

`app/Modules/Users/Forms/UserForm.php` extiende `Livewire\Form` y **no
persiste**: valida y empaqueta.
- Propiedades del usuario + `password_confirmation` + `role` + `permissions[]`
- `rules()` con validación condicional (password requerido sólo al crear; email
  único ignorando id propio), el rol contra el catálogo y las dos reglas
  anti-escalada
- `toData(): UserData` con el estado ya validado

```php
final class UserForm extends Form
{
    #[Locked]                 // ← imprescindible, ver más abajo
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public ?string $password = null;
    public ?string $password_confirmation = null;
    public string $role = SystemRole::User->value;
    /** @var array<int, string> */
    public array $permissions = [];

    public function rules(): array { /* ... + GrantableRole + GrantablePermission */ }
    public function toData(): UserData { /* estado → DTO */ }
}
```

> **Por qué `#[Locked]` en `$id`**: sin el candado, cualquiera con permiso
> `users.create` podía mandar `form.id` por `/livewire/update` y hacer que el
> guardado sobrescribiera a **otro usuario** (email, password, rol y permisos).
> `#[Locked]` sólo bloquea escrituras del cliente: `mount()` sigue pudiendo
> asignarlo del lado servidor con `fill()`.
>
> **Por qué el form ya no tiene `store()`** (v1.1): mientras la escritura vivía
> aquí, el caso de uso "crear un usuario" sólo existía dentro de un componente
> Livewire. Ahora las Actions lo hacen invocable desde cualquier sitio y
> testeable sin montar el componente.

## FormComponent — orquesta

`app/Modules/Users/Http/Livewire/FormComponent.php`:
- `#[Locked] public ?User $model = null` — modelo para edición
- `public UserForm $form` — el form object
- `mount()` autoriza y rellena el form si hay `model`
- `save(UserCreateAction $createUser, UserUpdateAction $updateUser)`:
  **autoriza → valida → `toData()` → Action → toast → redirect**. Las Actions
  llegan por inyección de método (Livewire las resuelve del contenedor)
- Computed properties: `title`, `roles`, `modules` — las dos últimas salen del
  `AuthorizationCatalog` y se serializan con `->toArray()` a la misma estructura
  que consumían antes el select y el Alpine de la matriz de permisos

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
  `$this->authorize('delete', $user)` **antes** de delegar en
  `UserDeleteAction`; luego dispara `users-updated`.

> La Action se resuelve con `resolve(...)` y **no** por inyección de método:
> cuando el diálogo de confirmación acepta, quien invoca el método es
> `InteractsWithFeedback::handleConfirmCallback()` de koreUi, que hace
> `$this->{$method}(...$params)` sin pasar por el contenedor. Un parámetro
> tipado de más reventaría en el navegador (y no en el test, que sí usa el
> contenedor).

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

## Anti-escalada de privilegios

`app/Modules/Users/Rules/`:

- **`GrantablePermission`** — el actor sólo puede conceder permisos que él mismo
  tiene.
- **`GrantableRole`** — el actor sólo puede asignar un rol si posee **todos** los
  permisos que ese rol concede (se mide en permisos, no en nombres de rol).

Ambas reciben el actor por constructor (nada de `auth()` dentro de la regla) y
el superadmin las salta. Sin ellas, `users.create` + `users.edit` era una
escalada a administrador en dos clics. Ver
[Autorización](../architecture/authorization.md#anti-escalada-de-privilegios).

> Limitación conocida: la matriz de permisos de la vista sigue mostrando todos
> los permisos del sistema aunque el actor no pueda concederlos. La validación
> los rechaza con el nombre del permiso en el mensaje; filtrarlos también en el
> cliente queda pendiente.

## Permisos involucrados

Auto-generados por `Module::permissions()` para el slug `users`:

- `users.view`
- `users.create`
- `users.edit`
- `users.delete`

`ModulesSeeder::seedRoles()` los reparte así:

| Rol | Permisos de `users` |
|-----|---------------------|
| `Role::SUPERADMIN` | todos (además del bypass por `Gate::before`) |
| `Role::ADMIN` | todos |
| `Role::USER` | ninguno — sólo `dashboard.view` |

El seeder sincroniza los permisos del superadmin aunque tenga el `Gate::before`,
para que los `@can` de las vistas sigan funcionando si algún día se quita ese
bypass.

## Auditoría

`App\Models\User` usa `Spatie\Activitylog\Models\Concerns\LogsActivity` y
registra en `activity_log` los cambios de `name` y `email` (nunca el password),
con el `causer` autenticado. Ver [`../ops/observability.md`](../ops/observability.md).

## Tests

`app/Modules/Users/Tests/Feature/`:

**`UserCreateActionTest.php` / `UserUpdateActionTest.php` /
`UserDeleteActionTest.php`** — los casos de uso, sin UI:
- Alta con rol y permisos, email verificado, password hasheado
- Rollback si el rol no existe (la transacción)
- Update con y sin cambio de password
- Borrado
- Cada uno dispara su evento (`Event::fake()`)

**`PrivilegeEscalationTest.php`** — las reglas anti-escalada:
- Un editor con `users.*` no puede conceder `roles.view` (error en
  `form.permissions.N`, con el permiso en el mensaje)
- No puede asignar `Administrador` (error en `form.role`)
- Sí puede conceder lo que tiene y asignar el rol que cubre
- Admin con todos los permisos y superadmin pasan
- También aplica al editar

**`UsersCrudTest.php`** — el CRUD feliz:
- Listado con/sin permiso
- Página create accesible para admin
- Crear usuario via Livewire form (con rol + permisos directos)
- Email duplicado rechazado
- Update sin cambiar password
- Tabla excluye superadmins
- El form dispara la Action correcta y su evento (create vs update vs delete)
- `UserForm::toData()` empaqueta el estado
- UserForm valida rol contra el catálogo
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
  3. Súmalo a `UserForm` (propiedad + rule + `toData()`) y a `UserData`
  4. Súmalo a `UserCreateAction` / `UserUpdateAction`
  5. Agrégalo a la vista `form-component.blade.php`
  6. Test en el test de la Action correspondiente
- **Agregar columna a la tabla** (ej. `phone`): agrega un `Column::make('Teléfono', 'phone')->searchable()` en `TableUsers::columns()`.
- **Filtros por más criterios**: agrega un `Filter` en `TableUsers::filters()` (ver `docs/guides/crud.md`).
- **Acciones bulk** (ej. desactivar múltiples): implementa `bulkActions()` y los métodos correspondientes.
