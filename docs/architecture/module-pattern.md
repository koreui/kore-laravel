# Patrón de módulo

**TL;DR**: Cada dominio vive en `app/Modules/{Domain}/` con su propio Provider, rutas, vistas, modelos, Actions y tests. Lo registra `bootstrap/providers.php`. Para crear uno nuevo, usa el skill `module-scaffold` o copia esta estructura.

## Estructura completa

```
app/Modules/{Domain}/
├── Actions/                       # 1 clase = 1 caso de uso (handle())
├── Data/                          # DTOs (extienden App\Core\Data\Data)
├── Http/
│   ├── Controllers/
│   ├── Livewire/                  # registrados explícitamente en el provider
│   └── Requests/
├── Models/
├── Policies/
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
    └── Feature/
```

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

## Comunicación entre módulos

❌ **NO** importar `App\Modules\OtroModulo\*` directamente desde otro módulo.

✅ **SÍ**:
- **Events** — el módulo origen dispara, el destino escucha. Los events viven en `App\Modules\Origen\Events\` y los listeners en `App\Modules\Destino\Listeners\`.
- **Contracts** — define una interfaz en `app/Core/Contracts/` y bind la implementación en el provider correspondiente. El módulo cliente type-hint la interfaz.
- **Actions públicas vía interfaz** — exponer una API mínima del dominio.

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
3. **Como referencia**: lee `app/Modules/Auth/` o `app/Modules/Tenancy/` — son módulos completos.
