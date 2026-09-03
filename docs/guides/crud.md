# Patrón CRUD del boilerplate

**TL;DR**: cada CRUD se compone de Form Object (validación) + DTO + Actions
(escritura) + Events + FormComponent (Livewire) + Table (`KoreDataTable`) +
Controller + rutas con `permission:` middleware. Estructura idéntica para cada
módulo. El módulo Users es el ejemplo de referencia y todo el código de esta
guía existe de verdad en `app/Modules/Users/`.

## Las piezas

```
app/Modules/{Modulo}/
├── Actions/                                      # 3. escritura (1 caso de uso = 1 clase)
│   ├── {Modelo}CreateAction.php
│   ├── {Modelo}UpdateAction.php
│   └── {Modelo}DeleteAction.php
├── Data/{Modelo}Data.php                         # 2. DTO de entrada de las Actions
├── Events/{Modelo}{Created|Updated|Deleted}.php  # 4. canal hacia otros módulos
├── Forms/{Modelo}Form.php                        # 1. Form Object (validación + toData)
├── Http/
│   ├── Controllers/{Modulo}Controller.php        # 7. Controller
│   └── Livewire/
│       ├── FormComponent.php                     # 5. autoriza → valida → DTO → Action
│       └── Table{Modelos}.php                    # 6. KoreDataTable
├── Policies/{Modelo}Policy.php                   # 9. el único punto de decisión (R25)
├── Rules/                                        # reglas de validación propias
├── Resources/
│   ├── lang/en.json                              # traducción (español es la fuente, R33)
│   └── views/
│       ├── pages/                                # blades index/create/edit
│       └── livewire/form-component.blade.php
├── Routes/web.php                                # 8. rutas con permission middleware
├── Providers/{Modulo}ModuleServiceProvider.php
└── Tests/Feature/                                # 10. uno por Action, más el flujo (R35)
```

El reparto de responsabilidades, en una línea:

| Pieza          | Hace                                    | NO hace                          |
|----------------|-----------------------------------------|----------------------------------|
| Form Object    | valida y empaqueta                      | no persiste                      |
| DTO (`Data`)   | transporta datos ya validados           | no valida, no tiene lógica       |
| Action         | escribe y dispara el evento             | no autoriza, no lee `auth()`     |
| Event          | avisa a otros módulos                   | —                                |
| FormComponent  | autoriza, orquesta, feedback y redirect | no escribe                       |

---

## 1. Form Object

Vive en `app/Modules/{Modulo}/Forms/{Modelo}Form.php`. Extiende `Livewire\Form`.

**Reglas**:
- Tiene `#[Locked] public ?int $id = null;` — **el `#[Locked]` no es opcional**:
  sin él, un cliente con permiso de *crear* puede mandar `form.id` por
  `/livewire/update` y sobrescribir cualquier registro.
- Tiene una propiedad pública por cada campo del modelo.
- Implementa `rules(): array` (devuelve un array, **no** uses `#[Validate]`).
- Implementa `toData(): {Modelo}Data`, que empaqueta el estado en el DTO.
- **No persiste nada.** Escribir es trabajo de las Actions.

```php
final class UserForm extends Form
{
    #[Locked]
    public ?int $id = null;

    public string $name = '';
    public string $email = '';
    public ?string $password = null;
    public ?string $password_confirmation = null;
    public string $role = SystemRole::User->value;

    /** @var array<int, string> */
    public array $permissions = [];

    public function rules(): array
    {
        $catalog = resolve(AuthorizationCatalog::class);
        $actor = auth()->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->id)],
            'password' => [$this->id !== null ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'role' => [
                'required',
                Rule::in($catalog->assignableRoleNames()),
                new GrantableRole($actor, $catalog),   // no puedes asignar más de lo que tienes
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name', new GrantablePermission($actor)],
        ];
    }

    public function toData(): UserData
    {
        return new UserData(
            name: $this->name,
            email: $this->email,
            password: $this->password === '' ? null : $this->password,
            role: $this->role,
            permissions: array_values($this->permissions),
        );
    }
}
```

> **Por qué el form no escribe**: mientras `store()` vivía aquí, el caso de uso
> "crear un usuario" sólo existía dentro de un componente Livewire. No se podía
> llamar desde un comando artisan, ni desde un job, ni testear sin montar el
> componente. Ver [`../architecture/module-pattern.md`](../architecture/module-pattern.md).

---

## 2. DTO

`app/Modules/{Modulo}/Data/{Modelo}Data.php`, `final`, extiende
`App\Core\Data\Data` (que es `spatie/laravel-data`). Propiedades promovidas y
`readonly`. **Cero lógica**: es el contrato entre la capa de entrega y el caso
de uso.

```php
final class UserData extends Data
{
    /** @param array<int, string> $permissions */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $password,
        public readonly string $role,
        public readonly array $permissions,
    ) {}
}
```

Un arch test vigila que todo `App\Modules\*\Data` sea `final` y extienda la base
de Core.

---

## 3. Actions

`app/Modules/{Modulo}/Actions/`, `final`, extienden `App\Core\Actions\Action`,
**un único método público `handle()`**. Naming: `{Domain}{Object}{Verb}Action`;
cuando el objeto coincide con el dominio se omite el prefijo repetido
(`UserCreateAction` en el módulo Users, no `UsersUserCreateAction`).

**Reglas**:
- Reciben DTOs y modelos, nunca arrays sueltos ni el `Request`.
- **Nada de `auth()` / `request()` / `session()` dentro**: la autorización la
  hace quien llama. Así la Action sirve igual desde un job o un comando.
- Agrupa en `DB::transaction()` sólo cuando varias escrituras tienen que caer
  juntas (crear el usuario + sincronizar rol y permisos, por ejemplo).
- Dispara su evento **fuera** de la transacción: un listener no debe ver datos
  sin commitear.

```php
final class UserCreateAction extends Action
{
    public function handle(UserData $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = new User;

            $user->fill([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make((string) $data->password),
                'email_verified_at' => now(),
            ])->save();

            $user->syncRoles([$data->role]);
            $user->syncPermissions($data->permissions);

            return $user;
        });

        event(new UserCreated($user));

        return $user;
    }
}
```

`UserUpdateAction::handle(User $user, UserData $data): User` y
`UserDeleteAction::handle(User $user): void` siguen el mismo molde.

Skill: `.agents/skills/kore-action-create/`.

---

## 4. Events

`app/Modules/{Modulo}/Events/`, `final readonly`, con el modelo como propiedad
pública. Son **el canal oficial** para que otro módulo reaccione sin importar
éste (regla 3 de CLAUDE.md).

```php
final readonly class UserCreated
{
    public function __construct(
        public User $user,
    ) {}
}
```

El listener vive en `App\Modules\{Otro}\Listeners\` y sólo depende del evento.
Laravel los descubre solo; no hace falta registrarlos.

---

## 5. FormComponent

`app/Modules/{Modulo}/Http/Livewire/FormComponent.php`.

**Reglas**:
- `use KoreUi\Core\Concerns\InteractsWithFeedback;` para toasts.
- `#[Locked] public ?{Modelo} $model = null;` — el modelo para editar.
- `public {Modelo}Form $form;` — siempre se llama `$form`.
- `mount()` **autoriza** (`create` / `update`) y rellena el form si hay modelo.
- `save()` hace **autorizar → validar → DTO → Action → toast → redirect**, y
  nada más.
- Las Actions llegan **por inyección de método**: Livewire resuelve del
  contenedor los parámetros que no viajan en la llamada del cliente.
- **La autorización va dentro del componente, siempre.** El middleware
  `permission:*` de las rutas no corre en `/livewire/update`; el `->hidden()` de
  un `RowAction` es sólo cosmética.
- Datos para la vista van en **computed properties** (`#[Computed]`), nunca en
  propiedades públicas sueltas.

```php
public function save(UserCreateAction $createUser, UserUpdateAction $updateUser): mixed
{
    if ($this->model instanceof User) {
        $this->authorize('update', $this->model);
    } else {
        $this->authorize('create', User::class);
    }

    $this->form->validate();

    $data = $this->form->toData();

    $user = $this->model instanceof User
        ? $updateUser->handle($this->model, $data)
        : $createUser->handle($data);

    $this->toast()
        ->success(__('¡Listo!'), __('Usuario guardado correctamente.'))
        ->viaSession()
        ->send();

    return to_route('users.index');
}
```

### Vista del FormComponent

```blade
<form wire:submit="save">
    <x-kore::card :title="$this->title">
        <div class="space-y-4">
            <x-kore::input id="form-nombre" :label="__('Nombre')"
                wire:model="form.nombre" name="form.nombre" />
            {{-- más componentes kore-ui aquí --}}
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-kore::button :href="route('{modulo}.index')" variant="ghost">
                    {{ __('Cancelar') }}
                </x-kore::button>
                <x-kore::button type="submit" icon="check"
                    wire:loading.attr="disabled" wire:target="save">
                    {{ __('Guardar') }}
                </x-kore::button>
            </div>
        </x-slot:footer>
    </x-kore::card>
</form>
```

> **IMPORTANTE**: Los inputs de texto que usen `wire:model.live` necesitan `id` estable (ej. `id="form-nombre"`). Sin `id`, Livewire pierde el foco al re-render. Los componentes basados en clic (`select`, `toggle`, `checkbox`, `radio`, `datepicker`) NO necesitan `id`.

> **Nada de Eloquent en la blade.** Lo que la vista necesite (opciones de un
> select, cifras, catálogos) se prepara en un `#[Computed]` y viaja como array o
> DTO. Ver `Auth\Http\Livewire\Dashboard`.

---

## 6. Table — KoreDataTable

`app/Modules/{Modulo}/Http/Livewire/Table{Modelos}.php` extiende `KoreUi\DataTable\KoreDataTable`.

**Mínimo**:
- `query(): Builder`
- `columns(): array`

**Opcional**: `configure()`, `filters()`, `bulkActions()`.

```php
final class Table{Modelos} extends KoreDataTable
{
    use InteractsWithFeedback;

    public function query(): Builder
    {
        return {Modelo}::query();
    }

    public function configure(): void
    {
        $this->setDefaultSort('created_at', 'desc');
    }

    #[On('{modulo}-updated')]
    public function refresh(): void
    {
        // Re-render
    }

    public function columns(): array
    {
        return [
            Column::make('#', 'id')->sortable()->width(80),
            Column::make(__('Nombre'), 'nombre')->sortable()->searchable(),
            DateColumn::make(__('Creado'), 'created_at')->sortable(),

            ActionColumn::make()->actions([
                RowAction::make('edit', __('Editar'))
                    ->icon('pencil')
                    ->urlPattern('/{modulo}/{id}/edit'),

                RowAction::make('delete', __('Eliminar'))
                    ->icon('trash')->color('destructive')
                    ->wireMethod('confirmDelete')
                    ->confirm(__('¿Eliminar este registro?'))
                    ->separator(),
            ]),
        ];
    }

    /**
     * Aquí la Action se resuelve a mano: cuando el diálogo de confirmación de
     * koreUi acepta, `handleConfirmCallback()` llama `$this->{$method}(...$params)`
     * sin pasar por el contenedor, así que un parámetro extra tipado reventaría
     * en el navegador (y NO en el test, que sí usa el contenedor).
     */
    public function confirmDelete(int $id): void
    {
        $model = {Modelo}::find($id);

        if (! $model instanceof {Modelo}) {
            return;
        }

        // Obligatorio: /livewire/update no pasa por el middleware `permission:*`
        // de las rutas, y el ->hidden() del RowAction es sólo cosmética.
        $this->authorize('delete', $model);

        resolve({Modelo}DeleteAction::class)->handle($model);

        $this->toast()->success(__('¡Listo!'), __('Eliminado.'))->send();
        $this->dispatch('{modulo}-updated');
    }
}
```

### Tipos de columnas

| Columna           | Para                                |
|-------------------|--------------------------------------|
| `Column`          | Texto plano                          |
| `BadgeColumn`     | Estados / categorías con colores     |
| `BooleanColumn`   | Check/cross para booleanos           |
| `DateColumn`      | Fechas formateadas                   |
| `NumberColumn`    | Números con format                   |
| `ImageColumn`     | Avatar / imagen                      |
| `LinkColumn`      | Enlace clickeable                    |
| `ComponentColumn` | Render Blade custom                  |
| `ActionColumn`    | Dropdown de acciones por fila        |

### Filtros disponibles

| Filtro              | SQL              |
|----------------------|------------------|
| `TextFilter`         | `LIKE %val%`     |
| `SelectFilter`       | `= val`          |
| `MultiSelectFilter`  | `IN (...)`       |
| `BooleanFilter`      | `= bool`         |
| `NumberFilter`       | `{op} val`       |
| `NumberRangeFilter`  | `BETWEEN`        |
| `DateFilter`         | `whereDate`      |
| `DateRangeFilter`    | `BETWEEN`        |

Para filtros con relaciones (whereHas, etc.) usa `->callback(fn (Builder $q, $v) => ...)`.

---

## 7. Controller

`app/Modules/{Modulo}/Http/Controllers/{Modulo}Controller.php`. Devuelve blades que tienen el componente Livewire dentro. **Sin lógica** — eso vive en las Actions.

```php
final class {Modulo}Controller extends Controller
{
    public function index(): View
    {
        return view('{modulo}::pages.index');
    }

    public function create(): View
    {
        return view('{modulo}::pages.create');
    }

    public function edit({Modelo} $model): View
    {
        return view('{modulo}::pages.edit', ['model' => $model]);
    }
}
```

### Blades

`pages/index.blade.php`:
```blade
<x-layouts.app :title="__('{Modulos}')">
    <livewire:{modulo}.table-{modelos} />
</x-layouts.app>
```

`pages/create.blade.php`:
```blade
<x-layouts.app :title="__('Nuevo {modelo}')">
    <livewire:{modulo}.form-component />
</x-layouts.app>
```

`pages/edit.blade.php`:
```blade
<x-layouts.app :title="__('Editar {modelo}')">
    <livewire:{modulo}.form-component :$model />
</x-layouts.app>
```

> Si una pantalla es **toda ella** un componente Livewire (como `/dashboard`),
> la ruta apunta directamente a la clase (`Route::get('/dashboard', Dashboard::class)`)
> y el componente elige layout y título desde `render()`:
> `view('auth::livewire.dashboard')->layout('components.layouts.app', ['title' => __('Dashboard')])`.

---

## 8. Rutas

`app/Modules/{Modulo}/Routes/web.php`:

```php
Route::middleware(['web', 'auth', 'verified'])
    ->prefix('{modulo}')
    ->as('{modulo}.')
    ->controller({Modulo}Controller::class)
    ->group(function (): void {
        Route::middleware('permission:{slug}.view')->get('/', 'index')->name('index');
        Route::middleware('permission:{slug}.create')->get('/create', 'create')->name('create');
        Route::middleware('permission:{slug}.edit')->get('/{model}/edit', 'edit')->name('edit');
    });
```

Cargado por el provider del módulo:

```php
$this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
```

---

## Provider del módulo

```php
final class {Modulo}ModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        '{modulo}.form-component' => FormComponent::class,
        '{modulo}.table-{modelos}' => Table{Modelos}::class,
    ];

    public function boot(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");
        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadViewsFrom("{$base}/Resources/views", '{modulo}');
        Blade::anonymousComponentPath("{$base}/Resources/views", '{modulo}');

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        Gate::policy({Modelo}::class, {Modelo}Policy::class);
    }
}
```

Y registrarlo en `bootstrap/providers.php`.

---

## Permisos

Antes de levantar el CRUD, agrega el módulo a `ModulesSeeder` y corre:

```bash
php artisan kore:regenerate-permissions
```

Esto genera los 4 permisos `{slug}.{view|create|edit|delete}` y los sincroniza a los administradores. Ver [`../architecture/authorization.md`](../architecture/authorization.md).

Si el formulario deja asignar roles o permisos, reutiliza
`Users\Rules\GrantableRole` y `Users\Rules\GrantablePermission` como modelo:
nadie debe poder conceder lo que él mismo no tiene.

---

## Tests obligatorios

En `app/Modules/{Modulo}/Tests/Feature/`. Mínimo:

- **Una clase por Action**: crea, actualiza con y sin password, borra, y que el
  evento se dispara (`Event::fake()`).
- Listado con permiso pasa, sin permiso devuelve 403.
- Crear vía Livewire: `Livewire::test(FormComponent::class)->set(...)->call('save')`
  redirige a index.
- Editar: pasa el modelo, cambia un campo, llama `save`.
- Validación: campo requerido falla con `assertHasErrors`.
- Autorización atacando el componente directamente (como haría un cliente por
  `/livewire/update`), no sólo por HTTP.
- Si hay Policy con reglas de negocio (no borrarse a uno mismo, etc.), test
  directo sobre el policy.

Referencia: `app/Modules/Users/Tests/Feature/` — `UserCreateActionTest`,
`UserUpdateActionTest`, `UserDeleteActionTest`, `UsersCrudTest`,
`UsersAuthorizationTest`, `PrivilegeEscalationTest`.

---

## Referencia de componentes UI

Los componentes `<x-kore::*>` (input, select, password, datepicker, upload, etc.) están documentados en el repo de koreUi. Cuando trabajes con AI, llama al MCP `mcp__kore-ui__get-component-docs` para la API actualizada.

---

## Skill de la AI

Para crear un módulo CRUD desde cero, pide a la AI ejecutar el skill `module-scaffold` (`.agents/skills/module-scaffold/SKILL.md`). Para crear sólo Actions o componentes Livewire, hay skills específicos: `kore-action-create`, `kore-livewire-create`.
