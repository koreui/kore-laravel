# Arquitectura — visión general

**TL;DR**: Modular Monolith + Action Pattern sobre Laravel 12. Cada dominio vive aislado en `app/Modules/{Domain}/`, conecta con otros sólo vía Events/Contracts, y la lógica de negocio se concentra en clases Action de un solo método.

## Stack

| Capa            | Tecnología                                  |
|-----------------|---------------------------------------------|
| Lenguaje        | PHP 8.3+ (soporta 8.4)                      |
| Framework       | Laravel 12                                  |
| UI              | Livewire 4 + Alpine.js + Tailwind CSS v4    |
| Componentes     | [koreUi](https://packagist.org/packages/kore-ui/kore-ui) — `<x-kore::*>` |
| Auth            | Fortify + Sanctum (toggle) + spatie/laravel-permission |
| DTOs            | spatie/laravel-data                         |
| Feature flags   | Laravel Pennant                             |
| Tenancy         | stancl/tenancy v3 (toggle)                  |
| Queues          | driver `database` (`queue:listen` en `composer dev`, `queue:work` en Docker) |
| Tests           | Pest 3 + arch tests (`tests/Arch/`)         |
| Calidad         | Pint + Larastan 8 + Rector                  |
| Observabilidad  | Sentry · Pulse · spatie/laravel-health · spatie/laravel-activitylog |
| AI              | Laravel Boost MCP + CLAUDE.md/AGENTS.md     |

## Estructura de carpetas

```
app/
├── Core/                          # kernel compartido, NO negocio
│   ├── Actions/Action.php         # base abstracta para Actions
│   ├── Concerns/                  # traits compartidos
│   ├── Contracts/                 # interfaces (puentes entre módulos)
│   ├── Data/Data.php              # base DTO (extiende spatie/laravel-data)
│   ├── Enums/                     # valores compartidos (SystemRole)
│   └── Support/                   # helpers
├── Modules/{Domain}/              # cada feature aislada (ver module-pattern.md)
├── Models/User.php                # único modelo verdaderamente global
└── Providers/
    ├── AppServiceProvider.php
    └── HealthServiceProvider.php
bootstrap/providers.php            # registra los providers
config/kore-app.php                # toggles (ver toggles.md)
```

## Por qué esta arquitectura

| Alternativa considerada | Por qué la rechazamos                                         |
|--------------------------|--------------------------------------------------------------|
| MVC clásico Laravel       | escala mal — controllers gordos, modelos sobre-cargados       |
| Hexagonal / Clean estricto | sobre-ingeniería para 80% de casos; fricción para AI         |
| DDD by-the-book           | rígido, lento al inicio; usar selectivamente dentro de módulos |
| Vertical slices puros     | escala mal cuando llegan 50+ features                         |

**Modular Monolith + Actions** combina lo bueno: empezás simple, escalás aislando dominios cuando duela, y la AI entiende cada módulo como un "mini-app" autocontenido.

## Reglas de oro

1. **1 Action = 1 caso de uso** con método `handle(...)`. Naming `{Domain}{Object}{Verb}Action`.
2. **Sin lógica gorda en controllers / Livewire** — pasa de 10 líneas, mover a Action.
3. **Sin imports cruzados entre módulos** (arch test, en ambos sentidos).
   Comunicación: Events, Contracts en `Core/Contracts/` (implementados en
   `{Domain}/Support/`), enums compartidos en `Core/Enums/`, o Actions públicas
   vía interfaz. `App\Core` nunca depende de `App\Modules`.
4. **DTOs (spatie/laravel-data) en lugar de arrays** entre capas.
5. **`declare(strict_types=1)`** obligatorio en todo PHP.
6. **`final class`** por defecto.
7. **Type hints** en TODO (params, returns, props).
8. **`CarbonImmutable`** por defecto (forzado en `AppServiceProvider`).
9. **Tests Pest** obligatorios para cada Action / endpoint Livewire / ruta.

Ver detalle en:
- [`module-pattern.md`](module-pattern.md) — cómo se construye un módulo
- [`toggles.md`](toggles.md) — `config/kore-app.php`
- [`../quality/pipeline.md`](../quality/pipeline.md) — pipeline que hace cumplir esto
