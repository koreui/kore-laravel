# Módulo Users

**TL;DR**: CRUD de usuarios con asignación de rol + permisos directos. Es el
módulo de referencia del boilerplate y ejemplifica el patrón completo: Form
Object (validación) + `UserData` (DTO) + Actions (escritura) + Events +
FormComponent + KoreDataTable + Controller + Policy + `permission` middleware +
reglas anti-escalada. Desde la v2.2.0 el mismo CRUD se publica por
[API](#api-v1-apiv1users), reutilizando esas Actions y esas reglas.

## Estructura

```
app/Modules/Users/
├── Actions/                            # los casos de uso (escriben)
│   ├── UserCreateAction.php
│   ├── UserUpdateAction.php
│   ├── UserAccountStatusChangeAction.php  # activar / suspender (toggle AUTH_INVITATIONS)
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
│       ├── AccountStatusPanel.php      # estado de la cuenta (toggle AUTH_INVITATIONS)
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
> (`FormComponent::save()`, `TableUsers::deleteAuthorized()`) — no hay rutas
> POST/PUT/DELETE explícitas.

> ⚠️ **El middleware `permission:*` de las rutas NO protege las acciones
> Livewire.** Esas peticiones van a `/livewire/update`, que sólo pasa por el
> grupo `web`. Toda autorización real vive **dentro** del componente
> (`$this->authorize(...)`). Ver [Autorización](#autorización).

## API v1 (`api/v1/users`)

Desde la v2.2.0 el módulo publica el mismo CRUD por API, y es el **endpoint de
referencia** del boilerplate: el que hay que copiar cuando un módulo nuevo
necesita publicar su recurso. Paso a paso y con `curl`, en
[`../guides/api.md`](../guides/api.md#users-api-v1).

| Verbo | URI | Nombre | Ability del token | Policy |
|-------|-----|--------|-------------------|--------|
| GET | `/api/v1/users` | `api.v1.users.index` | `users.view` | `viewAny` |
| GET | `/api/v1/users/{user}` | `api.v1.users.show` | `users.view` | `view` |
| POST | `/api/v1/users` | `api.v1.users.store` | `users.create` | `create` |
| PUT·PATCH | `/api/v1/users/{user}` | `api.v1.users.update` | `users.edit` | `update` |
| DELETE | `/api/v1/users/{user}` | `api.v1.users.destroy` | `users.delete` | `delete` |

Las rutas viven en `app/Modules/Users/Routes/api.php` y su provider las carga
sólo con `API_ENABLED=true`, igual que hace Auth.

**No reimplementa nada.** El controller usa las mismas `UserCreateAction`,
`UserUpdateAction` y `UserDeleteAction`, y los requests producen el mismo
`UserData` que `UserForm::toData()`. Lo único propio de la API son cuatro
archivos:

```
Http/
├── Controllers/Api/V1/UserController.php     # autoriza → DTO → Action → resource
├── Requests/Api/V1/
│   ├── UserApiRequest.php                    # base: reglas comunes + toData()
│   ├── UserStoreRequest.php                  # password required
│   └── UserUpdateRequest.php                 # password nullable
└── Resources/Api/V1/UserResource.php         # lista blanca de lo que sale
```

### Doble barrera (R23 · R25)

Cada ruta exige la ability del token **y** el método vuelve a preguntarle a la
Policy. Son dos preguntas distintas: la ability dice qué se le concedió a *este
token* cuando se emitió —lo que hace que un token robado de una app de sólo
lectura no borre nada aunque su dueño sea administrador—, y la Policy dice qué
puede *este usuario* ahora mismo sobre *este registro* («sólo un superadmin
edita a otro superadmin» no cabe en una ability).

Es la misma forma que tiene la UI: la ruta lleva `permission:users.edit` y el
componente Livewire vuelve a autorizar porque `/livewire/update` no pasa por el
middleware de la ruta.

### Las mismas guardas que la pantalla

- **El superadmin no sale en el listado**, exactamente como en `TableUsers`.
- **Editarlo y borrarlo** lo bloquea `UserPolicy` (403).
- **Borrarse a uno mismo** lo bloquea el controller con `abort_if`, porque el
  `Gate::before` del superadmin devuelve `true` antes de que la Policy opine.
- **Anti-escalada (R26)**: el rol y los permisos pasan por `GrantableRole` y
  `GrantablePermission`, las mismas reglas que el formulario. Un intento sale
  como 422 `validation_failed` con el motivo en `details`.

`UserResource` publica `id`, `name`, `email`, `roles`, `permissions` (los
**efectivos**) y `created_at`. Es una lista blanca: una columna nueva en la tabla
no se publica sola.

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
  llegan por inyección de método (Livewire las resuelve del contenedor), y las
  dos últimas etapas son una sola línea:
  `App\Core\Concerns\RedirectsWithToast::redirectWithToast()`, que manda el
  toast por sesión —lo único que sobrevive a la redirección—
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
- El par confirmar → borrar lo aporta `App\Core\Concerns\HandlesDeleteConfirmation`:
  el `RowAction` llama a `confirmDelete($id)`, el trait guarda el id en un
  `#[Locked] $pendingDeleteId` y aterriza en el hook del componente.
- `deleteAuthorized($id)` hace `abort_if($user->id === auth()->id(), 403)` y
  `$this->authorize('delete', $user)` **antes** de delegar en
  `UserDeleteAction`; luego dispara `users-updated`. Es público a propósito:
  así el check de R23 lo sigue viendo en el archivo del módulo.

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
| `authorize()` en componentes | `FormComponent::mount/save`, `TableUsers::deleteAuthorized` | —                        |
| `->hidden()` en RowActions | esconder botones                              | **no autoriza nada**                |

Puntos exactos donde se autoriza:

- `FormComponent::mount()` → `create` (alta) / `update` (edición)
- `FormComponent::save()` → `create` o `update`, **antes** de validar y escribir
- `TableUsers::deleteAuthorized()` → guarda de auto-borrado + `delete`

### UserPolicy

`app/Modules/Users/Policies/UserPolicy.php`:
- `viewAny`, `view`, `create`, `update` — chequean el permiso correspondiente
- `update` bloquea editar superadmin si quien edita no es superadmin
- `delete` bloquea: borrarse a uno mismo, borrar superadmin, no tener permiso `users.delete`

Registrada via `Gate::policy(User::class, UserPolicy::class)` en el provider.

> **Ojo con el `Gate::before` del superadmin** (`AuthModuleServiceProvider`):
> devuelve `true` antes de consultar la policy, así que para ese rol la policy
> **no se evalúa**. Por eso la guarda de «no borrarse a uno mismo» se repite
> como `abort_if` dentro de `TableUsers::deleteAuthorized()`.

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

## Panel de estado de la cuenta (`AUTH_INVITATIONS`)

`Users\Http\Livewire\AccountStatusPanel`, montado en `/users/{id}/edit`
**sólo** cuando `AUTH_INVITATIONS` está encendido: con el toggle apagado el
estado de la cuenta no gobierna nada y un panel para moverlo sería una palanca
desconectada. Ver [`auth.md`](auth.md#invitaciones-y-estado-de-cuenta) para el
flujo completo.

Qué hace: pinta el estado con `<x-kore::badge>` —etiqueta y color salen de
`App\Core\Enums\AccountStatus`, la vista no decide nada (R30)— y ofrece
**Activar** o **Suspender** según toque, vía `UserAccountStatusChangeAction`.

Tres decisiones:

- **Va fuera del `<form>` del formulario de edición**, en `users::pages.edit`.
  No es estilo: anidar un componente con botones dentro de otro formulario haría
  que un clic en «Activar» enviara el formulario del usuario.
- **Nadie cambia su propio estado**, y esa guarda vive en la **Action**, no en la
  Policy. Es la misma cicatriz que el auto-borrado de
  `TableUsers::deleteAuthorized()`: el `Gate::before` del superadmin devuelve
  `true` antes de consultar la policy, así que una regla escrita allí no
  aplicaría justo al rol que más daño puede hacerse. Sin ella, un superadmin
  puede suspenderse a sí mismo y quedarse fuera de la única pantalla desde la que
  podría revertirlo. La Action lanza `ConflictException` y el componente la
  convierte en un aviso, no en un 500.
- **Sólo un superadmin toca a otro superadmin.** Eso sí lo pone la Policy
  (`UserPolicy::update()`), que ya lo hacía para el formulario, y por eso en el
  panel basta con `authorize('update', $user)`.

`activated_at` se sella la **primera** vez que la cuenta se activa y no se toca
después: reactivar a alguien tras una suspensión no borra la fecha en la que
entró. Y `Auth\Events\AccountActivated` sólo se dispara cuando el estado
**cambia** a activo, así que volver a pulsar «Activar» sobre una cuenta activa no
vuelve a mandar el correo de bienvenida que escuche un derivado.

> **R5** · Users dispara un evento de Auth. Está permitido y es el único cruce:
> `{Otro}\Events\` es la frontera pública de un módulo. Lo que Users no importa
> es ninguna otra clase de Auth — el estado viaja por `App\Core\Enums`, y el
> toggle es una clave de configuración compartida, no una dependencia de código.

## Auditoría

`App\Models\User` usa `Spatie\Activitylog\Models\Concerns\LogsActivity` y
registra en `activity_log` los cambios de `name`, `email` y `account_status`
(nunca el password), con el `causer` autenticado. Que el cambio de estado quede
registrado no es un extra: es la respuesta a «¿quién suspendió esta cuenta y
cuándo?». Ver [`../ops/observability.md`](../ops/observability.md).

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

**`ApiUsersTest.php`** — el CRUD por API, con las dos barreras probadas por
separado (`Sanctum::actingAs($user, $abilities)` permite dar el permiso y negar
la ability, y al revés):
- 401 del invitado en los cinco verbos
- Listado: envelope + `meta`, paginación por cursor sin repetir ni saltarse
  filas, tope de `per_page`, filtros `search` y `role`, superadmins ocultos
- Cada verbo con y sin su ability, y con la ability pero sin el permiso
- `show`: 404 canónico y lista blanca de campos
- `store`: alta con rol y permisos, 422 con `details`, email duplicado
- **R26 por API**: ni un permiso ni un rol que el actor no tenga → 422
- `update`: `PUT` y `PATCH`, «omitir password = no la cambies», superadmin
  intocable
- `destroy`: 204 sin cuerpo, superadmin y auto-borrado bloqueados
- Con `API_ENABLED=false` no existe ninguna ruta

**`AccountStatusPanelTest.php`** — el panel de estado (`AUTH_INVITATIONS`):
- Suspender y reactivar, conservando la primera `activated_at`
- `activated_at` se sella la primera vez que se activa
- `AccountActivated` sólo se dispara cuando el estado cambia de verdad
- Nadie cambia su propio estado, superadmin incluido
- El cambio queda en el audit log
- R23 por `/livewire/update`: sin `users.edit`, 403; un no-superadmin no suspende
  a un superadmin
- El panel sólo aparece en `/users/{id}/edit` con el toggle encendido

Total Users: **98 tests / 350 assertions**. (Cifra real de
`./vendor/bin/pest app/Modules/Users --compact`; actualízala cuando cambie.)

## Cómo extender

- **Agregar campo nuevo al usuario** (ej. `phone`):
  1. Migration que agrega la columna
  2. Súmalo al `#[Fillable]` de `User` (obligatorio: ya no hay `Model::unguard()` global)
  3. Súmalo a `UserForm` (propiedad + rule + `toData()`) y a `UserData`
  4. Súmalo a `UserCreateAction` / `UserUpdateAction`
  5. Agrégalo a la vista `form-component.blade.php`
  6. Test en el test de la Action correspondiente
- **Agregar columna a la tabla** (ej. `phone`): agrega un `Column::make('Teléfono', 'phone')->searchable()` en `TableUsers::columns()`.
- **Filtros por más criterios**: agrega un `Filter` en `TableUsers::filters()` (ver `docs/guides/crud.md`).
- **Acciones bulk** (ej. desactivar múltiples): implementa `bulkActions()` y los métodos correspondientes.
