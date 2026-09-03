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
| Tests           | Pest 3 + arch tests (`tests/Arch/`) + E2E Playwright (`tests/e2e/`) |
| Calidad         | Pint + Larastan (larastan 3, nivel 8) + PHPat + `spaze/phpstan-disallowed-calls` + `kore:arch:check` + Rector |
| Observabilidad  | Sentry · Pulse · spatie/laravel-health · spatie/laravel-activitylog |
| AI              | Laravel Boost MCP + CLAUDE.md/AGENTS.md     |

## Estructura de carpetas

```
app/
├── Core/                          # kernel compartido, NO negocio
│   ├── Actions/Action.php         # base abstracta para Actions
│   ├── Concerns/                  # traits compartidos (hoy vacía)
│   ├── Console/                   # comandos transversales
│   │   ├── ArchCheckCommand.php   # kore:arch:check
│   │   └── Hooks/                 # hooks de git (pre-commit, pre-push)
│   ├── Contracts/                 # interfaces (puentes entre módulos)
│   │   └── AuthorizationCatalog.php
│   ├── Data/
│   │   ├── Data.php               # base DTO (extiende spatie/laravel-data)
│   │   └── Authorization/         # DTOs que cruzan la frontera Auth → Users
│   ├── Enums/                     # valores compartidos (SystemRole)
│   └── Support/                   # helpers (hoy vacía)
├── Modules/{Domain}/              # cada feature aislada (ver module-pattern.md)
├── Models/User.php                # único modelo verdaderamente global
└── Providers/
    ├── AppServiceProvider.php     # registra también los comandos de Core
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

Las reglas del boilerplate son un catálogo numerado y citable: la fuente de
verdad es [`rules.md`](rules.md), donde cada `R{n}` lleva su enunciado, quién la
verifica, con qué comando, con qué severidad, la válvula de escape que admite y
la cicatriz real que la originó. Este resumen sólo da el titular.

| Regla | Titular |
|-------|---------|
| [R1](rules.md) · [R2](rules.md) | 1 Action = 1 caso de uso con `handle()`; naming `{Domain}{Object}{Verb}Action` |
| [R4](rules.md) | sin lógica de negocio en controllers, Livewire ni Forms — pasa de ~10 líneas, va a una Action |
| [R5](rules.md) · [R6](rules.md) · [R7](rules.md) | sin imports cruzados entre módulos: Events, `Core/Contracts/` (implementados en `{Domain}/Support/`) o enums y DTOs de `Core`. `App\Core` nunca depende de `App\Modules`, y sus contratos son interfaces |
| [R8](rules.md) | DTOs (spatie/laravel-data) en lugar de arrays entre capas, `final` y con propiedades `readonly` |
| [R13](rules.md) | `declare(strict_types=1)` en todo el PHP de `app/` |
| [R14](rules.md) | `final class` por defecto |
| [R15](rules.md) | type hints completos (params, returns, propiedades) |
| [R16](rules.md) | `CarbonImmutable` por defecto (forzado en `AppServiceProvider`) |
| [R35](rules.md) | un test Pest por Action, componente Livewire y ruta |

Ver detalle en:
- [`rules.md`](rules.md) — **el catálogo completo `R1..R48`** (fuente de verdad)
- [`module-pattern.md`](module-pattern.md) — cómo se construye un módulo
- [`toggles.md`](toggles.md) — `config/kore-app.php`
- [`../quality/pipeline.md`](../quality/pipeline.md) — pipeline que hace cumplir esto
