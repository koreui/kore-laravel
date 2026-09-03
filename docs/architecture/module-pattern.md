# Patrón de módulo

**TL;DR**: Cada dominio vive en `app/Modules/{Domain}/` con su propio Provider, rutas, vistas, modelos, Actions y tests. Lo registra `bootstrap/providers.php`. Para crear uno nuevo, usa el skill `module-scaffold` o copia esta estructura.

## Estructura completa

```
app/Modules/{Domain}/
├── Actions/                       # 1 clase = 1 caso de uso (handle())
├── Data/                          # DTOs (final, extienden App\Core\Data\Data)
├── Events/                        # lo que otros módulos pueden escuchar
├── Forms/                         # Livewire Form Objects (validación + toData())
├── Http/
│   ├── Controllers/
│   ├── Livewire/                  # registrados explícitamente en el provider
│   └── Requests/
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
├── Resources/views/               # vistas namespaced (`{domain}::vista`)
├── Providers/{Domain}ModuleServiceProvider.php
└── Tests/
    ├── Feature/
    └── Unit/
```

Ninguna carpeta es obligatoria salvo `Providers/`: crea las que el módulo
necesite. Lo que **sí** es obligatorio es dónde va cada cosa:

| Carpeta   | Contiene                                    | Vigilado por arch test            |
|-----------|---------------------------------------------|-----------------------------------|
| `Actions/`| casos de uso, `final`, sufijo `Action`, extienden `App\Core\Actions\Action`, un solo `handle()` | sí |
| `Data/`   | DTOs `final` que extienden `App\Core\Data\Data` | sí                           |
| `Policies/`| `final`, sufijo `Policy`                   | sí                                |
| `Providers/`| `final`, sufijo `ServiceProvider`          | sí                                |

Si una clase la impone un paquete y no puede cumplir la convención (por ejemplo
los stubs que publica Fortify), **no** la metas en `Actions/`: dale su propia
carpeta y explica por qué. Ver `app/Modules/Auth/Fortify/`.

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

❌ **NO** importar `App\Modules\OtroModulo\*` directamente desde otro módulo.
Dos arch tests lo comprueban (`tests/Arch/ArchitectureTest.php`), ignorando sólo
las carpetas `Tests/`.

✅ **SÍ**:
- **Events** — el módulo origen dispara, el destino escucha. Los events viven en `App\Modules\Origen\Events\` y los listeners en `App\Modules\Destino\Listeners\`.
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

1. **Manual**: crea las carpetas, copia plantillas de arriba, registra el provider.
2. **Con la AI** (Claude Code / Codex): pide "crear módulo {Domain}". El skill `module-scaffold` (`.claude/skills/module-scaffold/SKILL.md`) tiene las instrucciones detalladas.
3. **Como referencia**: lee `app/Modules/Users/` (CRUD completo con Form + Data
   + Actions + Events + Rules), `app/Modules/Auth/` o `app/Modules/Tenancy/`.
