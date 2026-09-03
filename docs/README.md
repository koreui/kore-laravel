# Documentación de kore-laravel

Hub central de documentación. Las reglas vivas y resúmenes operativos están en `CLAUDE.md` y `AGENTS.md`; los detalles profundos viven aquí.

## Índice

### Arquitectura

- [`architecture/rules.md`](architecture/rules.md) — **catálogo R1–R48**: cada regla con su enforcement, su válvula de escape y la cicatriz que la originó
- [`architecture/overview.md`](architecture/overview.md) — stack, capas y decisiones
- [`architecture/module-pattern.md`](architecture/module-pattern.md) — cómo se construye un módulo (y qué carpetas puede tener)
- [`architecture/toggles.md`](architecture/toggles.md) — `config/kore-app.php`
- [`architecture/authorization.md`](architecture/authorization.md) — roles, permisos y modules

### Módulos

- [`modules/auth.md`](modules/auth.md) — Fortify + Sanctum + permission + 2FA + OTP + Socialite
- [`modules/tenancy.md`](modules/tenancy.md) — stancl/tenancy + activación opt-in
- [`modules/users.md`](modules/users.md) — módulo Users (primer CRUD del boilerplate)

### Guías

- [`guides/crud.md`](guides/crud.md) — patrón CRUD: Form Object + Data + Actions + Events + KoreDataTable
- [`guides/i18n.md`](guides/i18n.md) — español como idioma fuente, inglés por módulo en JSON

### Calidad

- [`quality/pipeline.md`](quality/pipeline.md) — Pint · Larastan · PHPat · disallowed-calls · `kore:arch:check` · Rector · Pest · hooks · CI
- [`quality/e2e.md`](quality/e2e.md) — Playwright: suite E2E, entorno aislado y convenciones

### Operación

- [`ops/deployment.md`](ops/deployment.md) — Docker en VPS (PHP-FPM + Nginx + MySQL + Redis)
- [`ops/observability.md`](ops/observability.md) — Sentry + Pulse + Health + ActivityLog

### AI

- [`ai/working-with-ai.md`](ai/working-with-ai.md) — Laravel Boost + CLAUDE/AGENTS + skills propios

### Auditorías

- [`audit/2026-09-02-auditoria-y-roadmap.md`](audit/2026-09-02-auditoria-y-roadmap.md) — auditoría de septiembre de 2026 y roadmap por versiones

Fuera de `docs/` vive también [`CHANGELOG.md`](../CHANGELOG.md): qué cambió en cada versión y qué aplicar al actualizar un proyecto derivado.

> Este índice no es decorativo: `php artisan kore:arch:check --rule=R40` falla si
> algún `docs/**/*.md` no aparece aquí. Doc nuevo, línea nueva.

## Cómo se usa

- **Trabajando en código**: lee `CLAUDE.md` (resumen + reglas de oro con su `R{n}`). Si necesitas el detalle de una regla, salta a [`architecture/rules.md`](architecture/rules.md).
- **Onboarding** de un colaborador (humano o AI): empieza por `architecture/overview.md`, sigue por `architecture/rules.md` y después `modules/{tu-area}.md`.
- **Deploy** o tarea de operación: `ops/deployment.md`.
- **Trabajando con la AI** (Claude Code, Codex, etc.): `ai/working-with-ai.md`.

## Convenciones de la documentación

- Archivos cortos y enfocados (un tema por archivo).
- Bullets > párrafos.
- Cada doc empieza con un **TL;DR** de 2-3 líneas.
- Ejemplos de código deben ser ejecutables tal cual o describir claramente el contexto.
- Si una decisión cambia, **actualiza el doc en el mismo commit** que el cambio de código (R40).
- Toda cifra que aparezca en un doc se actualiza en el commit que la cambia (R41). Un número inventado es peor que no ponerlo.
