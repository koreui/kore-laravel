# Patrón de módulo

**TL;DR**: Cada dominio vive en `app/Modules/{Domain}/` con su propio Provider, rutas, vistas, modelos, Actions y tests. Lo registra `bootstrap/providers.php`. La lista de carpetas es **cerrada** y la vigila un arch test. Para crear uno nuevo, usa el skill `module-scaffold` o copia esta estructura.

Las reglas que se citan aquí (`R3`, `R5`, `R9`...) están explicadas en [`rules.md`](rules.md).

## Estructura completa

```
app/Modules/{Domain}/
├── Actions/                       # 1 clase = 1 caso de uso (handle())
├── Console/Commands/              # comandos artisan del dominio
├── Data/                          # DTOs (final, extienden App\Core\Data\Data)
├── Enums/                         # enums backed del dominio
├── Events/                        # lo que otros módulos pueden escuchar
├── Exports/                       # salida hacia fuera: Excel, CSV, PDF
├── Forms/                         # Livewire Form Objects (validación + toData())
├── Http/
│   ├── Controllers/
│   ├── Livewire/                  # registrados explícitamente en el provider
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/                 # API Resources (extienden JsonResource)
├── Listeners/                     # reacciones a eventos de otros módulos
├── Models/
├── Policies/
├── Rules/                         # reglas de validación propias del dominio
├── Support/                       # implementaciones de contratos de Core, helpers
├── Routes/
│   ├── web.php
│   └── api.php                    # cargada sólo si API_ENABLED=true
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/
├── Resources/
│   ├── views/                     # vistas namespaced (`{domain}::vista`)
│   └── lang/                      # en.json del módulo (R33)
├── Providers/{Domain}ModuleServiceProvider.php
└── Tests/
    ├── Feature/
    └── Unit/
```

## R3 · La lista de carpetas es cerrada

Ninguna carpeta es obligatoria salvo `Providers/`: crea las que el módulo
necesite. Pero **no puedes inventarte una nueva**: el arch test
`R3 · un módulo sólo tiene las carpetas permitidas` recorre `app/Modules/*/*` y
falla ante cualquier nombre que no esté en esta lista.

| Carpeta | Contiene | Vigilado por |
|---------|----------|--------------|
| `Actions/` | casos de uso: `final`, sufijo `Action`, extienden `App\Core\Actions\Action`, un solo `handle()` público | Pest arch + PHPat (R1, R2) |
| `Console/` | comandos artisan del dominio, registrados por el provider | R3 |
| `Data/` | DTOs `final` que extienden `App\Core\Data\Data` y sólo dependen de datos | Pest arch + PHPat (R8) |
| `Database/` | sólo `Migrations/`, `Factories/` y `Seeders/` | R3, R29 |
| `Enums/` | enums **backed** (`string` o `int`) del dominio | Pest arch: «los Enums de módulo son enums backed» (R3) |
| `Events/` | lo que otros módulos pueden escuchar: `final readonly`. Es la **frontera pública**: otro módulo sí puede importarlos | Pest arch (R5) |
| `Exports/` | salida hacia fuera de la aplicación: Excel, CSV, PDF. Cómo se construye cada formato: [`../guides/exports.md`](../guides/exports.md) | R3 — lo de dentro lo fija el paquete de exportación |
| `Forms/` | Livewire Form Objects: `rules()` + `toData()`, sin persistencia | R4, R24 |
| `Http/` | sólo `Controllers/`, `Livewire/`, `Requests/`, `Middleware/` y `Resources/` | R3, R23 |
| `Http/Resources/` | API Resources que extienden `Illuminate\Http\Resources\Json\JsonResource` | Pest arch: «los Http/Resources de módulo extienden JsonResource» (R3) |
| `Listeners/` | reacciones a eventos de otros módulos | R3 |
| `Models/` | Eloquent, con `#[Fillable]` explícito (Laravel 13; antes `$fillable`) | R27 |
| `Policies/` | `final`, sufijo `Policy` | Pest arch (R25) |
| `Providers/` | `final`, sufijo `ServiceProvider` | Pest arch (R9) |
| `Resources/` | sólo `views/` y `lang/` | R3 |
| `Routes/` | `web.php` y `api.php` | R3 |
| `Rules/` | reglas de validación `final` que implementan `ValidationRule` | Pest arch (R14) |
| `Support/` | implementaciones de contratos de Core, helpers del módulo | R3 |
| `Tests/` | `Feature/` y `Unit/`; es lo único que puede cruzar módulos | R5 |
| `Fortify/` | **caso especial**: adaptadores de un paquete cuyo nombre y firma fija un tercero | R3 |

Dentro de `Models/`, `Support/`, `Actions/`, `Enums/` o `Exports/` cada módulo se
organiza como quiera: sólo se comprueban las subcarpetas de `Database/`, `Http/`
y `Resources/`, que son las tres con estructura fija.

### Cómo pedir una carpeta nueva

Una carpeta nueva es una capa nueva, así que no se añade «probando a ver»:

1. **Comprueba que no encaja en ninguna de las existentes.** La mayoría de los
   `Services/` que aparecen son Actions; la mayoría de los `Helpers/` son
   `Support/`; la mayoría de los `Transformers/` son `Data/`.
2. **Si de verdad es una capa nueva**, se decide con el equipo y se actualiza
   **en el mismo commit**: esta tabla, el `$allowed` del arch test
   (`tests/Arch/ArchitectureTest.php`) y, si aporta reglas propias, `rules.md`.
   Y si la carpeta tiene una forma verificable —«esto son enums backed», «esto
   extiende `JsonResource`»—, entra con su arch test: una carpeta permitida sin
   nada que la defina vuelve a ser el cajón de sastre que R3 quería cerrar.
3. **Si es un adaptador de un paquete** cuyo contrato no puedes cumplir (nombres
   y firmas impuestos, como los stubs de Fortify), **no** lo metas en `Actions/`:
   sigue el precedente de `app/Modules/Auth/Fortify/` y documenta por qué.

No hay válvula de escape para R3: la lista es la lista.

**`Services/` sigue fuera a propósito**, y es el ejemplo de qué cuesta abrir la
lista sin pensarlo: en Notarium acabó siendo un `ExpedienteService` de 1.147
líneas. El caso legítimo existe (asper-server tiene un motor de formularios con
estado propio en `Formats/Services/`), pero es la excepción que hay que
justificar delante del equipo, no el atajo por defecto. La cicatriz completa
está en R3 (`rules.md`).

## Service Provider del módulo

Plantilla mínima:

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
        $this->loadViewsFrom("{$base}/Resources/views", '{domain}');
        Blade::anonymousComponentPath("{$base}/Resources/views", '{domain}');

        if ((bool) config('kore-app.api.enabled') && file_exists($api = "{$base}/Routes/api.php")) {
            $this->loadRoutesFrom($api);
        }

        // Si el módulo tiene Livewire components:
        // \Livewire\Livewire::component('{domain}.foo', \App\Modules\{Domain}\Http\Livewire\Foo::class);
    }
}
```

## Toggles dentro de un módulo

Si el módulo entero debe ser opt-in, expón un flag en `config/kore-app.php` y haz **early return** en el provider antes de registrar nada:

```php
public function register(): void
{
    if (! (bool) config('kore-app.{domain}.enabled', false)) {
        return;
    }
    // ... registro normal
}
```

Ejemplo real: `app/Modules/Tenancy/Providers/TenancyModuleServiceProvider.php`. Ver [`../modules/tenancy.md`](../modules/tenancy.md).

## Factories del módulo

`AppServiceProvider::configureFactories()` enseña a Laravel la convención:

```
App\Modules\{Domain}\Models\{X} → App\Modules\{Domain}\Database\Factories\{X}Factory
```

Así la factory de un modelo de módulo vive **dentro del módulo** y no en
`database/factories/`. Basta con:

1. `use HasFactory;` en el modelo.
2. Crear `Database/Factories/{X}Factory.php` con `protected $model = {X}::class;`.

`App\Models\User` y cualquier modelo fuera de `App\Modules` siguen resolviendo a
`Database\Factories\` como en un Laravel de serie. Si un modelo no tiene
factory, el resolver lanza un `InvalidArgumentException` diciendo dónde la
buscó, en vez del "Class not found" de PHP.

Ejemplo real: `app/Modules/Auth/Database/Factories/{RoleFactory, ModuleFactory}.php`.

## Comunicación entre módulos

❌ **NO** importar `App\Modules\OtroModulo\*` directamente desde otro módulo
(R5). Lo comprueban dos arch tests de Pest (`tests/Arch/ArchitectureTest.php`) y,
sobre todo, PHPat: `tests/Arch/PhpatArchitecture.php` genera la regla para **cada
par** de módulos a partir de `glob(app/Modules/*)`, así que tu módulo nuevo queda
cubierto sin tocar nada.

✅ **SÍ**:
- **Events** — el módulo origen dispara, el destino escucha. Los events viven en `App\Modules\Origen\Events\` y los listeners en `App\Modules\Destino\Listeners\`. **`{Otro}\Events\` es la única parte del módulo vecino que se puede importar**: PHPat lo excluye del destino prohibido, para que el listener pueda tipar el evento y el provider cablearlo con `Event::listen(Evento::class, Listener::class)` en vez de con el nombre en un string. Las otras dos exclusiones son las carpetas `Tests/` de los dos lados.
- **Contracts** — define una interfaz en `app/Core/Contracts/` y bind la implementación en el provider correspondiente. El módulo cliente type-hint la interfaz.
- **Enums / DTOs en Core** — para valores compartidos (roles, estados) que no son de nadie en particular.
- **Actions públicas vía interfaz** — exponer una API mínima del dominio.

`App\Core` es el kernel compartido: todos pueden usarlo, él no puede depender de
ningún módulo (también hay arch test).

### Ejemplo real: Users ⟶ Auth

`Users` necesita saber qué roles y permisos existen, que son de `Auth`. En vez
de importar `Auth\Models\{Role, Module}`:

```
App\Core\Enums\SystemRole            ← el valor de los roles, compartido
App\Core\Contracts\AuthorizationCatalog   ← el contrato
App\Core\Data\Authorization\*        ← lo que devuelve el contrato
        ▲                                  ▲
        │                                  │
  App\Modules\Users                  App\Modules\Auth\Support\AuthorizationCatalog
  (consume `resolve(...)`)           (implementa; se bindea en su provider)
```

Y al revés, si mañana `Auth` (o `Billing`) necesita reaccionar a un alta de
usuario, escucha `App\Modules\Users\Events\UserCreated`; nunca llama a Users.

## Tests del módulo

Pest los descubre automáticamente. `tests/Pest.php` los extiende con `TestCase` + `RefreshDatabase`:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Feature');
```

Por lo que basta crear archivos `*.php` en `app/Modules/{Domain}/Tests/Feature/` con sintaxis Pest:

```php
<?php

declare(strict_types=1);

it('does something', function (): void {
    expect(true)->toBeTrue();
});
```

## Registrar el módulo

Agrega el provider a `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HealthServiceProvider::class,
    App\Modules\Auth\Providers\AuthModuleServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyModuleServiceProvider::class,
    App\Modules\Users\Providers\UsersModuleServiceProvider::class,
    App\Modules\{Tudominio}\Providers\{TuDominio}ModuleServiceProvider::class,
];
```

## Crear un módulo nuevo

Tres opciones, de menor a mayor automatización:

1. **Manual**: crea las carpetas (sólo las de la lista de R3), copia plantillas
   de arriba, registra el provider en `bootstrap/providers.php` y comprueba con
   `composer arch` + `composer analyse` antes del primer commit.
2. **Con la AI** (Claude Code / Codex): pide "crear módulo {Domain}". El skill `module-scaffold` (`.agents/skills/module-scaffold/SKILL.md`) tiene las instrucciones detalladas.
3. **Como referencia**: lee `app/Modules/Users/` (CRUD completo con Form + Data
   + Actions + Events + Rules), `app/Modules/Auth/` o `app/Modules/Tenancy/`.
