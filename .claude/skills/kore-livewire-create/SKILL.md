---
name: kore-livewire-create
description: Crear un componente Livewire 4 dentro de un módulo de kore-laravel, con su vista en Resources/views/ y registro en el ServiceProvider del módulo. Úsalo cuando el usuario pida "crear un componente Livewire", "agregar una página interactiva" o trabajar con componentes reactivos.
---

# Crear Livewire component en kore-laravel

## Reglas

- Vive en `app/Modules/{Domain}/Http/Livewire/{Component}.php`.
- Vista en `app/Modules/{Domain}/Resources/views/livewire/{component}.blade.php`.
- Registro **explícito** en `{Domain}ModuleServiceProvider::boot()` (no auto-discovery).
- Usa **siempre** componentes koreUi: `<x-kore::input>`, `<x-kore::password>`, `<x-kore::button>`, `<x-kore::card>`, `<x-kore::input-otp>`, etc. — nunca Flux UI ni otras librerías.
- **Toda escritura va en una Action** (skill `kore-action-create`). El componente
  hace **autorizar → validar → DTO → Action → feedback → redirect**, nada más.
- Las Actions llegan **por inyección de método**: Livewire resuelve del
  contenedor los parámetros que no viajan en la llamada del cliente.
  Excepción: los callbacks de confirmación de koreUi
  (`RowAction::confirm()`) invocan el método sin pasar por el contenedor, así
  que ahí se resuelve a mano con `resolve({Action}::class)` y se comenta el
  porqué.
- **Autoriza dentro del componente, siempre.** Las peticiones Livewire van a
  `/livewire/update`, donde el middleware `permission:*` de las rutas NO corre.
- `final class`, `declare(strict_types=1)`, tipos en propiedades públicas.
- Validación inline en métodos vía `$this->validate([...])`, o un Form Object en
  `Forms/` con `rules()` + `toData()` si el formulario tiene entidad propia.
- **Nada de Eloquent en la blade**: lo que la vista necesite se calcula en un
  `#[Computed]` y viaja como array o DTO.
- Los datos para la vista van en computed properties, nunca en propiedades
  públicas sueltas (viajan en el snapshot en cada request).

## Plantilla del componente

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Http\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

final class {Component} extends Component
{
    public string $someField = '';

    public function submit({Domain}{Object}CreateAction $create{Object}): void
    {
        $this->authorize('create', {Model}::class);

        $this->validate([
            'someField' => ['required', 'string', 'max:255'],
        ]);

        $create{Object}->handle(new {Object}Data(name: $this->someField));

        $this->dispatch('something-happened');
    }

    /**
     * Datos para la vista: siempre computed, nunca Eloquent en la blade.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function options(): array
    {
        return array_map(
            fn ({Object}Data $item): array => $item->toArray(),
            resolve({Domain}Catalog::class)->all(),
        );
    }

    public function render(): mixed
    {
        return view('{domain}::livewire.{component}');
    }
}
```

## Plantilla de la vista

```blade
<div class="space-y-4">
    <form wire:submit="submit" class="space-y-4">
        <x-kore::input
            wire:model="someField"
            name="someField"
            label="{{ __('Etiqueta') }}"
            required
        />

        <x-kore::button type="submit" class="w-full">
            {{ __('Guardar') }}
        </x-kore::button>
    </form>
</div>
```

### Componente de página completa

Si la pantalla **entera** es el componente, la ruta apunta a la clase y el
componente elige layout y título en `render()`:

```php
// Routes/web.php
Route::get('/dashboard', Dashboard::class)->name('dashboard');

// El componente
public function render(): View
{
    return view('{domain}::livewire.{component}')
        ->layout('components.layouts.app', ['title' => __('Título')]);
}
```

Ejemplo real: `App\Modules\Auth\Http\Livewire\Dashboard`.

## Registro en el provider

Agrega al ServiceProvider del módulo:

```php
private const array LIVEWIRE_COMPONENTS = [
    '{domain}.{component-kebab}' => \App\Modules\{Domain}\Http\Livewire\{Component}::class,
];

public function boot(): void
{
    // ...
    foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
        \Livewire\Livewire::component($alias, $class);
    }
}
```

## Test mínimo (Pest)

```php
<?php

declare(strict_types=1);

use App\Modules\{Domain}\Http\Livewire\{Component};
use Livewire\Livewire;

it('renders the component', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test({Component}::class)
        ->assertOk();
});

it('blocks the action without permission', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test({Component}::class)
        ->call('submit')
        ->assertForbidden();
});

it('validates required fields', function (): void {
    Livewire::test({Component}::class)
        ->set('someField', '')
        ->call('submit')
        ->assertHasErrors(['someField' => 'required']);
});
```

## Después de crear

- `composer ci` para validar Pint + Larastan + Rector + Pest.
- Si el componente expone una ruta (`Route::get('/x', {Component}::class)`), agrega test feature que verifique la URL.
