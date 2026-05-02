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
- **Observabilidad** Sentry + Laravel Pulse + spatie/laravel-health + spatie/laravel-activitylog
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

## Documentación

Reglas vivas y resúmenes en `CLAUDE.md` / `AGENTS.md`. Detalles en [`docs/`](docs/README.md):

- [`docs/architecture/`](docs/architecture/) — overview, module-pattern, toggles
- [`docs/modules/`](docs/modules/) — auth, tenancy
- [`docs/ops/`](docs/ops/) — deployment, observability
- [`docs/quality/`](docs/quality/) — pipeline (Pint, Larastan, Rector, Pest, hooks, CI)
- [`docs/ai/`](docs/ai/) — trabajar con la AI (Boost, skills)

> `koreUi` se consume desde Packagist (`kore-ui/kore-ui ^1.1`). No hay path repository; un `composer install` lo instala como cualquier otra dependencia.

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

## Observabilidad

| Tool                       | Status      | Endpoint     | Activación                          |
| -------------------------- | ----------- | ------------ | ----------------------------------- |
| **Sentry**                 | instalado   | —            | `SENTRY_LARAVEL_DSN` en `.env`      |
| **Laravel Pulse**          | instalado   | `/pulse`     | `PULSE_ENABLED=true`                |
| **spatie/laravel-health**  | instalado   | `/health`    | siempre (config en `HealthServiceProvider`) |
| **spatie/laravel-activitylog** | instalado | —          | añade `LogsActivity` trait al modelo |

## Despliegue en producción (Docker)

Stack incluido (`docker-compose.prod.yml`): PHP-FPM 8.4 + Nginx + MySQL 8.4 + Redis 7, con queue worker y scheduler como servicios separados. El Nginx interno escucha en `127.0.0.1:8081` para que el Nginx del host maneje TLS y haga `proxy_pass`.

```bash
# En el VPS:
git clone <repo> /opt/kore-laravel && cd /opt/kore-laravel
cp .env.example .env && nano .env

mkdir -p secrets
openssl rand -base64 32 > secrets/db_root_password.txt
openssl rand -base64 32 > secrets/db_password.txt

export GIT_SHA=$(git rev-parse --short HEAD)
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d
```

Detalles completos (firewall, SSH, fail2ban, server block del Nginx del host, despliegue de nuevas versiones, troubleshooting): [`docs/ops/deployment.md`](docs/ops/deployment.md).

## Trabajar con la AI

Este repo incluye `CLAUDE.md`, `AGENTS.md`, `.mcp.json` y skills locales en `.claude/skills/` y `.agents/skills/`. Cualquier asistente compatible (Claude Code, Codex, etc.) los detecta automáticamente.

**Skills propios:**
- `module-scaffold` — crear un módulo nuevo en `app/Modules/{Domain}/` con todo el patrón
- `kore-action-create` — crear una Action (caso de uso) siguiendo `{Domain}{Object}{Verb}Action`
- `kore-livewire-create` — crear un componente Livewire 4 con vistas koreUi y registro en provider

Skills oficiales (de Laravel Boost): `laravel-best-practices`, `livewire-development`, `pennant-development`, `pest-testing`.

Lee `CLAUDE.md` para entender las reglas de oro: arquitectura, naming, toggles y qué NO hacer.

## Licencia

MIT
