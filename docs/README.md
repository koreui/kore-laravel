# Documentación de kore-laravel

Hub central de documentación. Las reglas vivas y resúmenes operativos están en `CLAUDE.md` y `AGENTS.md`; los detalles profundos viven aquí.

## Estructura

```
docs/
├── architecture/      # cómo está pensada la app
│   ├── overview.md            stack, capas, decisiones
│   ├── module-pattern.md      cómo se construye un módulo
│   ├── toggles.md             config/kore-app.php
│   └── authorization.md       roles, permisos y modules
├── modules/           # documentación por módulo
│   ├── auth.md                Fortify + Sanctum + permission + 2FA + OTP + Socialite
│   ├── tenancy.md             stancl/tenancy + activación opt-in
│   └── users.md               módulo Users (primer CRUD del boilerplate)
├── guides/            # guías reutilizables
│   └── crud.md                patrón CRUD: Form Object + FormComponent + KoreDataTable
├── ops/               # operación y producción
│   ├── deployment.md          Docker en VPS (PHP-FPM + Nginx + MySQL + Redis)
│   └── observability.md       Sentry + Pulse + Health + ActivityLog
├── quality/           # pipeline de calidad
│   ├── pipeline.md            Pint + Larastan + Rector + Pest + hooks + CI
│   └── e2e.md                 Playwright: suite E2E, entorno aislado y convenciones
└── ai/                # capa AI-friendly
    └── working-with-ai.md     Laravel Boost + CLAUDE/AGENTS + skills propios
```

Fuera de `docs/` viven también [`CHANGELOG.md`](../CHANGELOG.md) (qué cambió en cada versión y qué aplicar al actualizar un proyecto derivado) y [`docs/audit/`](audit/) (auditorías puntuales con su roadmap).

## Cómo se usa

- **Trabajando en código**: lee `CLAUDE.md` (resumen + reglas de oro). Si necesitas profundizar, salta al doc relevante de aquí.
- **Onboarding** de un colaborador (humano o AI): empieza por `architecture/overview.md`, después `modules/{tu-area}.md`.
- **Deploy** o tarea de operación: `ops/deployment.md`.
- **Trabajando con la AI** (Claude Code, Codex, etc.): `ai/working-with-ai.md`.

## Convenciones de la documentación

- Archivos cortos y enfocados (un tema por archivo).
- Bullets > párrafos.
- Cada doc empieza con un **TL;DR** de 2-3 líneas.
- Ejemplos de código deben ser ejecutables tal cual o describir claramente el contexto.
- Si una decisión cambia, **actualiza el doc en el mismo PR** que el cambio de código.
