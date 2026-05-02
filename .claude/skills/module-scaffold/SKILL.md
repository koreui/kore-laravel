---
name: module-scaffold
description: Crear un módulo nuevo en app/Modules/{Domain} siguiendo el patrón Modular Monolith + Action de kore-laravel. Úsalo cuando el usuario pida "crear un módulo", "scaffold un dominio nuevo" o "agregar un Module" (ej. Billing, Orders, Inventory).
---

# Crear módulo en kore-laravel

## Cuándo usar
- El usuario quiere agregar un dominio nuevo (Billing, Orders, Inventory, Notifications, etc.) y aún no existe la carpeta `app/Modules/{Nombre}/`.

## Estructura objetivo

Para un módulo `{Domain}` (PascalCase), generar:

```
app/Modules/{Domain}/
├── Actions/                       # 1 clase = 1 caso de uso
├── Data/                          # DTOs (extienden App\Core\Data\Data)
├── Http/
│   ├── Controllers/
│   ├── Livewire/
│   └── Requests/
├── Models/
├── Policies/
├── Routes/
│   ├── web.php
│   └── api.php                    # solo si el módulo expone API
├── Database/
│   ├── Migrations/
│   ├── Factories/
│   └── Seeders/
├── Resources/views/               # vistas namespaced (`{domain}::`)
├── Providers/{Domain}ModuleServiceProvider.php
└── Tests/Feature/
```

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
6. **Crea un test inicial** en `Tests/Feature/{Domain}ModuleTest.php` que verifique al menos que el provider se registra. El path es auto-detectado por `tests/Pest.php`.
7. **Ejecuta** `composer dump-autoload && composer ci` para confirmar que todo queda verde.

## Reglas de oro (ver CLAUDE.md)

- `declare(strict_types=1)` obligatorio en todo archivo PHP nuevo.
- `final class` por default en clases sin herencia esperada.
- Naming de Actions: `{Domain}{Object}{Verb}Action` (`OrderCancelAction`, `BillingInvoiceCreateAction`).
- Sin imports cruzados a otros `Modules\*`. Si necesitas comunicar entre módulos: Events, Contracts en `app/Core/Contracts/`, o llamar Actions públicas vía interfaz.
- Componentes UI: `<x-kore::*>` (siempre). Nunca otra librería.
- DTOs en lugar de arrays asociativos entre capas.

## Después de crear

- Confirma con `composer ci` (Pint + Larastan + Rector + Pest).
- Si el provider necesita lógica condicional (toggles), añade el test correspondiente.
- Agrega los nuevos comandos artisan al README si los hubo.
