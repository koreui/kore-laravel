# kore-laravel

Boilerplate Laravel 12 production-ready con Livewire 4, Tailwind CSS v4, koreUi y stack AI-friendly.

## Stack

- **PHP** 8.3+ (soporta 8.4) · **Laravel** 12
- **UI** Livewire 4 + Alpine.js + Tailwind CSS v4 + [koreUi](../koreUi)
- **Auth** Fortify + Sanctum (toggle) + spatie/laravel-permission (Fase 2)
- **DTOs** spatie/laravel-data
- **Feature flags** Laravel Pennant
- **Tests** Pest 3 + arch tests
- **Calidad** Pint + Larastan nivel 8 + Rector (Fase 3)
- **Multi-tenancy** stancl/tenancy v3 (toggle, Fase 4)
- **Observabilidad** Sentry + Pulse + spatie/laravel-health (Fase 5)
- **AI** Laravel Boost (MCP) + CLAUDE.md + AGENTS.md + skills locales

## Arquitectura

Modular Monolith + Action Pattern. Ver `CLAUDE.md` para reglas detalladas.

```
app/
├── Core/                  # kernel compartido (Actions, Data, Concerns, Contracts, Support)
├── Modules/{Domain}/      # cada feature aislada
└── Models/User.php        # único modelo global
```

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev               # servidor + queue + logs + vite
```

### Activar multi-tenancy (opcional)

```bash
php artisan kore:tenancy:enable    # publica configs+migraciones, escribe TENANCY_ENABLED=true
php artisan migrate                # crea la tabla tenants
php artisan tenants:create         # crea tu primer tenant
```

Para desactivarlo, `TENANCY_ENABLED=false` en `.env` y todo el módulo deja de bootear (provider hace early return).

> **Nota**: este boilerplate consume `koreUi` desde `../koreUi/kore-ui` vía repositorio path con symlink. Asegúrate de tener el repo `koreUi` clonado al lado, o ajusta `composer.json` → `repositories` para apuntar al path/VCS correcto.

## Toggles (`.env`)

| Variable             | Default       | Activa                          |
| -------------------- | ------------- | ------------------------------- |
| `API_ENABLED`        | `true`        | Sanctum + rutas API             |
| `TENANCY_ENABLED`    | `false`       | Multi-tenancy (stancl/tenancy)  |
| `REVERB_ENABLED`     | `false`       | WebSockets (Reverb)             |
| `OCTANE_ENABLED`     | `false`       | Octane / FrankenPHP             |
| `SCOUT_ENABLED`      | `false`       | Scout + Meilisearch             |
| `AUTH_2FA_ENABLED`   | `true`        | 2FA                             |
| `AUTH_MAGIC_LINKS`   | `true`        | Magic links / OTP               |
| `AUTH_SOCIAL_LOGIN`  | `false`       | Socialite                       |
| `SENTRY_ENABLED`     | `false`       | Sentry                          |
| `PULSE_ENABLED`      | `false`       | Laravel Pulse                   |

## Comandos útiles

```bash
composer dev            # arranca todo (server + queue + logs + vite)
composer test           # corre Pest 3
./vendor/bin/pest --parallel
./vendor/bin/pest --filter=NombreTest

# Quality stack (configurado en Fase 3)
composer lint           # Pint
composer analyse        # PHPStan / Larastan
composer refactor       # Rector
composer ci             # todo lo anterior

# Boost MCP (la IA lo arranca solo)
php artisan boost:mcp
```

## Trabajar con la AI

Este repo incluye `CLAUDE.md`, `AGENTS.md`, `.mcp.json` y skills locales bajo `.claude/skills/` y `.agents/skills/`. Cualquier asistente compatible (Claude Code, Codex, etc.) los detecta automáticamente.

Lee `CLAUDE.md` para entender las reglas de oro: arquitectura, naming, toggles y qué NO hacer.

## Licencia

MIT
