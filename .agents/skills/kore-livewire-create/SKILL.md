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
- Lógica de negocio gorda → mover a una Action (skill `kore-action-create`). El componente solo orquesta UI + valida + llama Action.
- `final class`, `declare(strict_types=1)`, tipos en propiedades públicas.
- Validación inline en métodos vía `$this->validate([...])` o atributos `#[Validate(...)]`.

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

    public function submit(): void
    {
        $this->validate([
            'someField' => ['required', 'string', 'max:255'],
        ]);

        // Llama Action correspondiente
        // app(SomeAction::class)->handle(...);

        $this->dispatch('something-happened');
    }

    #[Layout('{domain}::layouts.app')]
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
    Livewire::test({Component}::class)
        ->assertOk();
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
