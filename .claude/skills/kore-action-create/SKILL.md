---
name: kore-action-create
description: Crear una Action (caso de uso) dentro de un módulo en app/Modules/{Domain}/Actions/. Úsalo cuando el usuario pida "crear una action", "implementar el caso de uso X" o agregar lógica de negocio que NO debe vivir en controller/Livewire.
---

# Crear Action en kore-laravel

## Cuándo usar

- Hay lógica de negocio que excede 10 líneas dentro de un controller, componente Livewire, o cualquier callsite.
- El usuario describe un caso de uso concreto: "registrar usuario", "cancelar orden", "generar factura", "enviar invitación al equipo".

## Reglas

- **1 Action = 1 caso de uso.** Un solo método público `handle(...)` con parámetros tipados explícitos.
- Naming: `{Domain}{Object}{Verb}Action`. Ejemplos:
  - `UserRegisterAction`
  - `OrderCancelAction`
  - `BillingInvoiceCreateAction`
  - `TeamInvitationSendAction`
- Recibe DTOs (no arrays). Si la entrada es compleja, crea un DTO en `app/Modules/{Domain}/Data/{Verb}{Object}Data.php` extendiendo `App\Core\Data\Data` (basado en `spatie/laravel-data`).
- `final class` siempre.
- `declare(strict_types=1)` obligatorio.
- Tests Pest obligatorios en `app/Modules/{Domain}/Tests/Feature/{Action}Test.php`.

## Plantilla

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Actions;

use App\Modules\{Domain}\Data\{Input}Data;

final class {Domain}{Object}{Verb}Action
{
    public function handle({Input}Data $data): {Return}
    {
        // 1. Validaciones de negocio (si aplica)
        // 2. Operación principal (DB / external services)
        // 3. Eventos / notificaciones
        // 4. Retorna el resultado
    }
}
```

## Llamado típico

Desde un controller / componente Livewire:

```php
public function store({Verb}{Object}Data $data, {Domain}{Object}{Verb}Action $action): RedirectResponse
{
    $result = $action->handle($data);

    return redirect()->route('...');
}
```

Laravel resuelve la action via constructor/method injection — **no usar `app()->make()` ni `new`** en producción.

## Comunicación entre módulos

- ❌ No importes `App\Modules\OtroModulo\*` directamente.
- ✅ Define un Contract en `app/Core/Contracts/` y bind en el provider correspondiente.
- ✅ O dispara un Event y deja que el otro módulo escuche.

## Test mínimo

```php
<?php

declare(strict_types=1);

use App\Modules\{Domain}\Actions\{Action};
use App\Modules\{Domain}\Data\{Input}Data;

it('runs the use case', function (): void {
    $action = app({Action}::class);

    $result = $action->handle({Input}Data::from([...]));

    expect($result)->toBe(...);
});
```

## Después de crear

- `composer ci` debe quedar verde (Pint + Larastan + Rector + Pest).
