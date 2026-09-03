---
name: module-scaffold
description: Crear un módulo nuevo en app/Modules/{Domain} siguiendo el patrón Modular Monolith + Action de kore-laravel. Úsalo cuando el usuario pida "crear un módulo", "scaffold un dominio nuevo" o "agregar un Module" (ej. Billing, Orders, Inventory).
compatibility: "kore-laravel (Laravel 13, Livewire 4, Pest 5). Claude Code, Codex y cualquier cliente Agent Skills."
---

# Crear módulo en kore-laravel

## Cuándo usar
- El usuario quiere agregar un dominio nuevo (Billing, Orders, Inventory, Notifications, etc.) y aún no existe la carpeta `app/Modules/{Nombre}/`.

## Estructura objetivo — lista CERRADA (R3)

Para un módulo `{Domain}` (PascalCase), generar sólo carpetas de esta lista:

```
app/Modules/{Domain}/
├── Actions/                       # 1 clase = 1 caso de uso (handle()), extienden Core\Actions\Action
├── Console/Commands/              # comandos artisan del dominio
├── Data/                          # DTOs final que extienden App\Core\Data\Data
├── Events/                        # lo que otros módulos pueden escuchar (final readonly)
├── Forms/                         # Livewire Form Objects (rules() + toData())
├── Http/
│   ├── Controllers/
│   ├── Livewire/
│   ├── Middleware/
│   └── Requests/
├── Listeners/                     # reacciones a eventos de otros módulos
├── Models/
├── Policies/
├── Rules/                         # reglas de validación propias (final, ValidationRule)
├── Support/                       # implementaciones de contratos de Core
├── Routes/
│   ├── web.php
│   └── api.php                    # solo si el módulo expone API
├── Database/
│   ├── Migrations/
│   ├── Factories/                 # {X}Factory de los modelos del módulo
│   └── Seeders/
├── Resources/
│   ├── views/                     # vistas namespaced (`{domain}::`)
│   └── lang/                      # en.json del módulo
├── Providers/{Domain}ModuleServiceProvider.php
└── Tests/Feature/
```

Crea sólo las carpetas que el módulo necesite; `Providers/` es la única
obligatoria.

⚠️ **La lista es cerrada y la vigila un arch test** (`R3 · un módulo sólo tiene
las carpetas permitidas`). Nada de `Services/`, `Repositories/`, `Helpers/` o
`Transformers/`: casi siempre son `Actions/`, `Support/` o `Data/`. Si de verdad
hace falta una capa nueva, **para y pregúntale al usuario**: ampliar la lista
toca `docs/architecture/module-pattern.md`, el `$allowed` de
`tests/Arch/ArchitectureTest.php` y `docs/architecture/rules.md` en el mismo
commit. La única excepción existente son los adaptadores de un paquete cuyo
contrato fija un tercero (`app/Modules/Auth/Fortify/`).

## Pasos

1. **Crea las carpetas** con `mkdir -p` en una sola llamada Bash.
2. **Genera el ServiceProvider** con esta plantilla mínima:

```php
<?php

declare(strict_types=1);

namespace App\Modules\{Domain}\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

final class {Domain}ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");
        $this->loadMigrationsFrom("{$base}/Database/Migrations");
        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
        $this->loadViewsFrom("{$base}/Resources/views", strtolower('{Domain}'));
        Blade::anonymousComponentPath("{$base}/Resources/views", strtolower('{Domain}'));

        if ((bool) config('kore-app.api.enabled') && file_exists($api = "{$base}/Routes/api.php")) {
            $this->loadRoutesFrom($api);
        }
    }
}
```

3. **Registra el provider** agregándolo a `bootstrap/providers.php` después de los providers existentes.
4. **Crea Routes/web.php** con un placeholder válido:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('{domain}')->group(function (): void {
    // Define rutas aquí
});
```

5. **Si el módulo necesita un toggle**, agrégalo a `config/kore-app.php` y haz que el provider haga `return` temprano cuando esté apagado. Documenta el toggle en `CLAUDE.md` y `.env.example`.
6. **Si el módulo tiene modelos**, añade `use HasFactory;` y crea
   `Database/Factories/{X}Factory.php`: el resolver de
   `AppServiceProvider::configureFactories()` ya mapea
   `App\Modules\{Domain}\Models\{X}` → `App\Modules\{Domain}\Database\Factories\{X}Factory`.
7. **Crea un test inicial** en `Tests/Feature/{Domain}ModuleTest.php` que verifique al menos que el provider se registra. El path es auto-detectado por `tests/Pest.php`.
8. **Ejecuta** `composer dump-autoload && composer arch && composer ci` para
   confirmar que todo queda verde. `composer arch` tarda 0,2 s y es el que
   detecta la carpeta inventada, el `#[Locked]` que falta, la migración sin
   `down()` y el doc nuevo sin enlazar.

## Reglas de oro (catálogo completo: `docs/architecture/rules.md`)

- **R13** · `declare(strict_types=1)` obligatorio en todo archivo PHP nuevo.
- **R14** · `final class` por default en clases sin herencia esperada.
- **R1 · R2** · Naming de Actions: `{Domain}{Object}{Verb}Action` (`OrderCancelAction`,
  `BillingInvoiceCreateAction`); si el objeto coincide con el dominio, se omite
  el prefijo repetido (`UserCreateAction` en el módulo Users). Extienden
  `App\Core\Actions\Action` y exponen un único `handle()`.
- **R4 · R23** · La escritura vive en las Actions, no en el Form Object ni en el
  componente Livewire: éste **autoriza → valida → DTO → Action**. El
  `authorize()` va dentro del componente, porque `/livewire/update` no pasa por
  el middleware `permission:` de la ruta.
- **R24** · Toda propiedad pública que identifique un modelo (`$id`, `$model`,
  `$algoId`) lleva `#[Locked]`.
- **R5** · Sin imports cruzados a otros `Modules\*` (lo verifican Pest arch y
  PHPat, que genera la regla para cada par de módulos). Si necesitas
  comunicar entre módulos: Events (`{Domain}\Events\`), Contracts en
  `app/Core/Contracts/` implementados en `{Domain}\Support\`, enums compartidos
  en `app/Core/Enums/`, o llamar Actions públicas vía interfaz.
- **R6** · `App\Core` nunca depende de `App\Modules`.
- **R31** · Componentes UI: `<x-kore::*>` (siempre). Nunca otra librería.
- **R8** · DTOs en lugar de arrays asociativos entre capas.
- **R30** · Nada de Eloquent en las blades: prepara los datos en un `#[Computed]`.
- **R29** · Toda migración del módulo define `down()`.
- **R11** · Si añades un toggle, alguien tiene que leerlo con
  `config('kore-app.{clave}')`, o `composer arch` lo marca como fantasma.
- **R44** · Si el código necesita una excepción a cualquiera de estas reglas,
  **no la escribas tú**: para y pregúntale al usuario, que es quien firma el
  `@owner` de la válvula.

## Módulo de referencia

`app/Modules/Users/` es el CRUD completo con Form + Data + Actions + Events +
Rules + Policy + tests. Cópialo cuando dudes; la guía es
[`docs/guides/crud.md`](../../../docs/guides/crud.md).

## Después de crear

- Confirma con `composer ci` (Pint + Larastan/PHPat/disallowed-calls +
  `kore:arch:check` + Rector + Pest).
- Si el provider necesita lógica condicional (toggles), añade el test correspondiente.
- Agrega los nuevos comandos artisan al README si los hubo.
