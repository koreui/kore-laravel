# Patrón CRUD del boilerplate

**TL;DR**: cada CRUD se compone de 5 piezas — Form Object (`Livewire\Form`), FormComponent (Livewire), Table (`KoreDataTable`), Controller (devuelve blades), rutas con `permission:` middleware. Estructura idéntica para cada módulo. El módulo Users es el ejemplo de referencia.

## Las 5 piezas

```
app/Modules/{Modulo}/
├── Forms/{Modelo}Form.php                        # 1. Form Object
├── Http/
│   ├── Controllers/{Modulo}Controller.php        # 4. Controller
│   └── Livewire/
│       ├── FormComponent.php                     # 2. Wraps Form Object
│       └── Table{Modelos}.php                    # 3. KoreDataTable
├── Resources/views/
│   ├── pages/                                    # blades index/create/edit
│   └── livewire/form-component.blade.php
└── Routes/web.php                                # 5. Rutas con permission middleware
```

---

## 1. Form Object

Vive en `app/Modules/{Modulo}/Forms/{Modelo}Form.php`. Extiende `Livewire\Form`.

**Reglas**:
- Tiene `public ?int $id = null;` (usado para `updateOrCreate`).
- Tiene una propiedad pública por cada campo del modelo.
- Implementa `rules(): array` (devuelve un array, **no** uses `#[Validate]`).
- Implementa `store(): Model` que ejecuta `Model::updateOrCreate(['id' => $this->id], [...])` y devuelve el modelo.
- Si hay relaciones / pivots, gestionarlos al final de `store()` con el modelo recién creado/actualizado.

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Modulo}\Forms;

use App\Modules\{Modulo}\Models\{Modelo};
use Livewire\Form;

class {Modelo}Form extends Form
{
    public ?int $id = null;
    public string $nombre = '';
    // ... resto de propiedades

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
        ];
    }

    public function store(): {Modelo}
    {
        $model = {Modelo}::updateOrCreate(
            ['id' => $this->id],
            ['nombre' => $this->nombre],
        );

        $this->id = $model->id;

        return $model;
    }
}
```

---

## 2. FormComponent

Vive en `app/Modules/{Modulo}/Http/Livewire/FormComponent.php`.

**Reglas**:
- `use KoreUi\Core\Concerns\InteractsWithFeedback;` para toasts.
- `#[Locked] public ?{Modelo} $model = null;` — el modelo para editar.
- `public {Modelo}Form $form;` — siempre se llama `$form`.
- `mount()` rellena el form si hay modelo.
- `save()` valida, llama a `$this->form->store()`, dispara toast y redirige.
- Datos para la vista van en **computed properties** (`#[Computed]`), nunca en propiedades públicas sueltas.
- `#[Layout('layouts.app')]` envuelve con el layout global.

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Modulo}\Http\Livewire;

use App\Modules\{Modulo}\Forms\{Modelo}Form;
use App\Modules\{Modulo}\Models\{Modelo};
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
final class FormComponent extends Component
{
    use InteractsWithFeedback;

    #[Locked]
    public ?{Modelo} $model = null;

    public {Modelo}Form $form;

    public function mount(): void
    {
        if (! $this->model instanceof {Modelo}) {
            return;
        }

        $this->form->fill([
            'id'     => $this->model->id,
            'nombre' => $this->model->nombre,
        ]);
    }

    public function save(): mixed
    {
        $this->form->validate();
        $this->form->store();

        $this->toast()
            ->success(__('¡Listo!'), __('Registro guardado.'))
            ->viaSession()
            ->send();

        return to_route('{modulo}.index');
    }

    #[Computed]
    public function title(): string
    {
        return $this->model instanceof {Modelo}
            ? __('Editar registro')
            : __('Crear registro');
    }

    public function render(): mixed
    {
        return view('{modulo}::livewire.form-component');
    }
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

---

## 3. Table — KoreDataTable

`app/Modules/{Modulo}/Http/Livewire/Table{Modelos}.php` extiende `KoreUi\DataTable\KoreDataTable`.

**Mínimo**:
- `query(): Builder`
- `columns(): array`

**Opcional**: `configure()`, `filters()`, `bulkActions()`.

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Modulo}\Http\Livewire;

use App\Modules\{Modulo}\Models\{Modelo};
use Illuminate\Database\Eloquent\Builder;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use KoreUi\DataTable\Actions\RowAction;
use KoreUi\DataTable\Columns\ActionColumn;
use KoreUi\DataTable\Columns\Column;
use KoreUi\DataTable\Columns\DateColumn;
use KoreUi\DataTable\KoreDataTable;
use Livewire\Attributes\On;

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

    public function confirmDelete(int $id): void
    {
        {Modelo}::find($id)?->delete();
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

## 4. Controller

`app/Modules/{Modulo}/Http/Controllers/{Modulo}Controller.php`. Devuelve blades que tienen el componente Livewire dentro. **Sin lógica gorda** — eso vive en el Form Object o en una Action.

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Modulo}\Http\Controllers;

use App\Modules\{Modulo}\Models\{Modelo};
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

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

---

## 5. Rutas

`app/Modules/{Modulo}/Routes/web.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\{Modulo}\Http\Controllers\{Modulo}Controller;
use Illuminate\Support\Facades\Route;

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

---

## Tests obligatorios

Crea `app/Modules/{Modulo}/Tests/Feature/{Modulo}CrudTest.php`. Mínimo:

- Listado con permiso pasa, sin permiso devuelve 403.
- Página create accesible para alguien con `{slug}.create`.
- Crear via Livewire form: `Livewire::test(FormComponent::class)->set(...)->call('save')` redirige a index.
- Editar: pasa el modelo, cambia un campo, llama save.
- Validación: campo requerido falla con `assertHasErrors`.
- Si hay Policy con reglas de negocio (no borrarse a uno mismo, etc.), test directo sobre el policy.

Ver `app/Modules/Users/Tests/Feature/UsersCrudTest.php` como referencia.

---

## Referencia de componentes UI

Los componentes `<x-kore::*>` (input, select, password, datepicker, upload, etc.) están documentados en el repo de koreUi. Cuando trabajes con AI, llama al MCP `mcp__kore-ui__get-component-docs` para la API actualizada.

---

## Skill de la AI

Para crear un módulo CRUD desde cero, pide a la AI ejecutar el skill `module-scaffold` (`.claude/skills/module-scaffold/SKILL.md`). Para crear sólo Actions o componentes Livewire, hay skills específicos: `kore-action-create`, `kore-livewire-create`.
