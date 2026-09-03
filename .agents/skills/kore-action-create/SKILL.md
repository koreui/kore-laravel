---
name: kore-action-create
description: Crear una Action (caso de uso) dentro de un módulo en app/Modules/{Domain}/Actions/. Úsalo cuando el usuario pida "crear una action", "implementar el caso de uso X" o agregar lógica de negocio que NO debe vivir en controller/Livewire.
compatibility: "kore-laravel (Laravel 13, Livewire 4, Pest 5). Claude Code, Codex y cualquier cliente Agent Skills."
---

# Crear Action en kore-laravel

## Cuándo usar

- Hay lógica de negocio que excede 10 líneas dentro de un controller, componente Livewire, Form Object o cualquier callsite.
- El usuario describe un caso de uso concreto: "registrar usuario", "cancelar orden", "generar factura", "enviar invitación al equipo".

## Reglas (catálogo completo: `docs/architecture/rules.md`)

- **R1** · **1 Action = 1 caso de uso.** Un solo método público `handle(...)` con
  parámetros tipados explícitos. PHPat lo verifica con
  `haveOnlyOnePublicMethodNamed('handle')`: un segundo método público falla
  `composer analyse`.
- **R1 · R13 · R14** · `final class`, **extiende `App\Core\Actions\Action`**,
  `declare(strict_types=1)`.
- **R2** · Naming: `{Domain}{Object}{Verb}Action`. Cuando el objeto coincide con el dominio, no repitas el prefijo:
  - `UserCreateAction` (módulo Users), no `UsersUserCreateAction`
  - `AuthUserRegisterAction` (módulo Auth: el objeto no es el dominio)
  - `OrderCancelAction`, `BillingInvoiceCreateAction`, `TeamInvitationSendAction`
- **R8** · Recibe **DTOs y modelos**, nunca arrays sueltos ni el `Request`. Si la
  entrada es compleja, crea un DTO en `app/Modules/{Domain}/Data/{Object}Data.php`
  (`final`, extiende `App\Core\Data\Data`, propiedades promovidas y `readonly`).
- **R19** · **Prohibido `auth()`, `request()`, `session()` y `cookie()` dentro de
  la Action**; el actor se pasa por constructor o por parámetro. La autorización
  la hace quien llama (el componente Livewire o el controller). Así la Action
  sirve igual desde un job, un comando artisan o un seeder. Lo bloquea
  `phpstan-disallowed.neon` (`kore.r19`).
- **R4 · R19** · Tampoco `Livewire\*` ni `Illuminate\Http\Request` como
  dependencia: PHPat lo comprueba con `testElDominioNoDependeDeLaCapaDeEntrega`.
- **R20** · Nada de `abort()` / `abort_if()` / `abort_unless()`: eso es capa Http.
  Desde una Action, lanza una excepción de dominio.
- **R21** · Nada de `DB::table()`: eso es de migraciones y seeders. Aquí se usan
  modelos Eloquent (`DB::transaction()` sí, obviamente).
- Envuelve en `DB::transaction()` sólo si varias escrituras tienen que caer juntas, y **dispara el evento fuera** de la transacción.
- **R5 · R14** · Si el caso de uso interesa a otros módulos, dispara un evento
  `final readonly` desde `app/Modules/{Domain}/Events/`.
- **R35** · Tests Pest obligatorios en
  `app/Modules/{Domain}/Tests/Feature/{Action}Test.php` (ahí corre `RefreshDatabase`).
- **R44** · Si crees que una de estas reglas no aplica a tu caso, **no escribas
  una válvula ni un `@phpstan-ignore`**: para y pregúntale al usuario.

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

## Comunicación entre módulos (R5)

- ❌ No importes `App\Modules\OtroModulo\*` directamente. Lo verifican Pest arch
  y PHPat (que genera la regla para cada par de módulos).
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

- `composer arch` (0,2 s) y después `composer ci`, que debe quedar verde: Pint +
  Larastan/PHPat/disallowed-calls + `kore:arch:check` + Rector + Pest.
