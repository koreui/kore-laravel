---
name: kore-action-create
description: Crear una Action (caso de uso) dentro de un módulo en app/Modules/{Domain}/Actions/. Úsalo cuando el usuario pida "crear una action", "implementar el caso de uso X" o agregar lógica de negocio que NO debe vivir en controller/Livewire.
---

# Crear Action en kore-laravel

## Cuándo usar

- Hay lógica de negocio que excede 10 líneas dentro de un controller, componente Livewire, Form Object o cualquier callsite.
- El usuario describe un caso de uso concreto: "registrar usuario", "cancelar orden", "generar factura", "enviar invitación al equipo".

## Reglas

- **1 Action = 1 caso de uso.** Un solo método público `handle(...)` con parámetros tipados explícitos.
- `final class`, **extiende `App\Core\Actions\Action`**, `declare(strict_types=1)`.
- Naming: `{Domain}{Object}{Verb}Action`. Cuando el objeto coincide con el dominio, no repitas el prefijo:
  - `UserCreateAction` (módulo Users), no `UsersUserCreateAction`
  - `AuthUserRegisterAction` (módulo Auth: el objeto no es el dominio)
  - `OrderCancelAction`, `BillingInvoiceCreateAction`, `TeamInvitationSendAction`
- Recibe **DTOs y modelos**, nunca arrays sueltos ni el `Request`. Si la entrada es compleja, crea un DTO en `app/Modules/{Domain}/Data/{Object}Data.php` (`final`, extiende `App\Core\Data\Data`, propiedades promovidas y `readonly`).
- **Prohibido `auth()`, `request()` y `session()` dentro de la Action.** La autorización la hace quien llama (el componente Livewire o el controller). Así la Action sirve igual desde un job, un comando artisan o un seeder.
- Envuelve en `DB::transaction()` sólo si varias escrituras tienen que caer juntas, y **dispara el evento fuera** de la transacción.
- Si el caso de uso interesa a otros módulos, dispara un evento `final readonly` desde `app/Modules/{Domain}/Events/`.
- Tests Pest obligatorios en `app/Modules/{Domain}/Tests/Feature/{Action}Test.php` (ahí corre `RefreshDatabase`).

## Plantilla

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Actions;

use App\Core\Actions\Action;
use App\Modules\{Domain}\Data\{Object}Data;
use App\Modules\{Domain}\Events\{Object}{Verb}ed;
use Illuminate\Support\Facades\DB;

final class {Domain}{Object}{Verb}Action extends Action
{
    public function handle({Object}Data $data): {Return}
    {
        $model = DB::transaction(function () use ($data): {Return} {
            // 1. Validaciones de negocio (si aplica)
            // 2. Escritura principal + relaciones que deben caer juntas
            return $result;
        });

        // 3. Eventos / notificaciones, ya commiteado
        event(new {Object}{Verb}ed($model));

        return $model;
    }
}
```

## DTO de entrada

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Data;

use App\Core\Data\Data;

final class {Object}Data extends Data
{
    /** @param array<int, string> $tags */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $tags,
    ) {}
}
```

Sin lógica dentro: el DTO transporta datos **ya validados**. Quien valida es el
Form Object (`rules()` + `toData()`) o un FormRequest.

## Evento

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Events;

final readonly class {Object}Created
{
    public function __construct(
        public {Model} ${model},
    ) {}
}
```

## Llamado típico

Desde un componente Livewire (Livewire resuelve del contenedor los parámetros
que no viajan en la llamada del cliente):

```php
public function save({Domain}{Object}CreateAction $create{Object}): mixed
{
    $this->authorize('create', {Model}::class);

    $this->form->validate();

    $create{Object}->handle($this->form->toData());

    return to_route('{modulo}.index');
}
```

Desde un controller: inyección por parámetro del método igual. **No uses `new`**
y evita `app()` (Rector lo reescribe a `resolve()`); si tienes que resolver a
mano —por ejemplo en un callback de koreUi que no pasa por el contenedor— usa
`resolve({Action}::class)` y explica por qué en un comentario.

## Comunicación entre módulos

- ❌ No importes `App\Modules\OtroModulo\*` directamente. Hay arch test.
- ✅ Define un Contract en `app/Core/Contracts/` y bindéalo en el provider del módulo dueño (ejemplo real: `App\Core\Contracts\AuthorizationCatalog` ↔ `App\Modules\Auth\Support\AuthorizationCatalog`).
- ✅ O dispara un Event y deja que el otro módulo escuche.
- ✅ Los valores compartidos (roles, estados) viven en `app/Core/Enums/`.

## Test mínimo

```php
<?php

declare(strict_types=1);

use App\Modules\{Domain}\Actions\{Action};
use App\Modules\{Domain}\Data\{Object}Data;
use App\Modules\{Domain}\Events\{Object}Created;
use Illuminate\Support\Facades\Event;

it('runs the use case', function (): void {
    $result = resolve({Action}::class)->handle(new {Object}Data(
        name: 'Algo',
        description: null,
        tags: [],
    ));

    expect($result->name)->toBe('Algo');
});

it('dispatches its event', function (): void {
    Event::fake([{Object}Created::class]);

    $result = resolve({Action}::class)->handle(new {Object}Data(/* ... */));

    Event::assertDispatched({Object}Created::class, fn ({Object}Created $event): bool => $event->{model}->is($result));
});
```

## Referencia viva

`app/Modules/Users/Actions/{UserCreateAction, UserUpdateAction, UserDeleteAction}.php`
con su `UserData`, sus eventos y sus tests.

## Después de crear

- `composer ci` debe quedar verde (Pint + Larastan + Rector + Pest).
