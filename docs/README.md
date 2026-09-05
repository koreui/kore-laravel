# Documentación de kore-laravel

Hub central de documentación. Las reglas vivas y resúmenes operativos están en `CLAUDE.md` y `AGENTS.md`; los detalles profundos viven aquí.

## Índice

### Arquitectura

- [`architecture/rules.md`](architecture/rules.md) — **catálogo R1–R62**: cada regla con su enforcement, su válvula de escape y la cicatriz que la originó
- [`architecture/overview.md`](architecture/overview.md) — stack, capas y decisiones
- [`architecture/module-pattern.md`](architecture/module-pattern.md) — cómo se construye un módulo (y qué carpetas puede tener)
- [`architecture/toggles.md`](architecture/toggles.md) — `config/kore-app.php`
- [`architecture/authorization.md`](architecture/authorization.md) — roles, permisos y modules

### Módulos

- [`modules/auth.md`](modules/auth.md) — Fortify + Sanctum + permission + 2FA + OTP + Socialite
- [`modules/tenancy.md`](modules/tenancy.md) — stancl/tenancy + activación opt-in
- [`modules/users.md`](modules/users.md) — módulo Users (primer CRUD del boilerplate)
- [`modules/docs.md`](modules/docs.md) — visor de `docs/` en `/docs` detrás de `DOCS_ENABLED`
- [`modules/devices.md`](modules/devices.md) — inventario de dispositivos que consumen la API, detrás de `DEVICES_ENABLED`
- [`modules/files.md`](modules/files.md) — archivos con versionado por slot y URL firmada, detrás de `FILES_ENABLED`
- [`modules/notifications.md`](modules/notifications.md) — bandeja in-app, campana, preferencias por categoría y API para el móvil, detrás de `NOTIFICATIONS_ENABLED`
- [`modules/e2e.md`](modules/e2e.md) — harness de la suite E2E (`/__e2e__/*`) detrás de `E2E_HARNESS`
- [`modules/pdf.md`](modules/pdf.md) — generación de PDF con spatie/laravel-pdf y Gotenberg, detrás de `PDF_ENABLED`
- [`modules/platform.md`](modules/platform.md) — ajustes en base de datos, series de folio y features por instalación. **Sin toggle: siempre encendido**
- [`modules/mx.md`](modules/mx.md) — utilidades de México (catálogo SEPOMEX e importe en letra), detrás de `MX_ENABLED`
- [`modules/webhooks.md`](modules/webhooks.md) — webhooks salientes con firma HMAC y outbox con reintentos, detrás de `WEBHOOKS_ENABLED`

### Guías

- [`guides/api.md`](guides/api.md) — API REST: el contrato de `App\Core\Http\Api`, cómo se añade un endpoint, middleware, limiters y Scramble
- [`guides/crud.md`](guides/crud.md) — patrón CRUD: Form Object + Data + Actions + Events + KoreDataTable
- [`guides/exports.md`](guides/exports.md) — la carpeta `Exports/`: CSV sin dependencias, Excel en el derivado y PDF delegando en el módulo Pdf
- [`guides/i18n.md`](guides/i18n.md) — español como idioma fuente, inglés por módulo en JSON

### Calidad

- [`quality/pipeline.md`](quality/pipeline.md) — Pint · Larastan · PHPat · disallowed-calls · `kore:arch:check` · Rector · Pest · hooks · CI
- [`quality/e2e.md`](quality/e2e.md) — Playwright: suite E2E, entorno aislado y convenciones
- [`quality/manual.md`](quality/manual.md) — el manual de usuario generado desde recorridos E2E (`npm run manual`) y su PDF con Gotenberg

### Manual de usuario

Se **genera**: lo escriben los recorridos de `tests/e2e/manual/*.guia.ts`. Sólo
el índice se versiona; las guías y sus capturas son artefactos ignorados, y aun
así entran en este índice porque R40 mira el disco. Guía nueva, línea nueva.

- [`manual/README.md`](manual/README.md) — índice del manual, regenerado en cada corrida
- [`manual/01-usuarios.md`](manual/01-usuarios.md) — guía de ejemplo: entrar, listar, crear, editar y buscar usuarios

### Patrones

- [`patterns/README.md`](patterns/README.md) — la regla de tres: cuándo una solución sube al boilerplate y con qué formato
- [`patterns/toggle-provider.md`](patterns/toggle-provider.md) — provider que no registra nada con el toggle apagado
- [`patterns/test-con-otro-entorno.md`](patterns/test-con-otro-entorno.md) — arrancar la aplicación con otras variables de entorno en un test

### Operación

- [`ops/deployment.md`](ops/deployment.md) — Docker en VPS (PHP-FPM + Nginx + MySQL + Redis)
- [`ops/observability.md`](ops/observability.md) — Sentry + Pulse + Health + ActivityLog
- [`ops/upgrading-from-boilerplate.md`](ops/upgrading-from-boilerplate.md) — actualizar un proyecto derivado desde el upstream (y devolverle mejoras)

### AI

- [`ai/working-with-ai.md`](ai/working-with-ai.md) — Laravel Boost + CLAUDE/AGENTS + skills propios

### Auditorías

- [`audit/2026-09-02-auditoria-y-roadmap.md`](audit/2026-09-02-auditoria-y-roadmap.md) — auditoría de septiembre de 2026 y roadmap por versiones
- [`audit/2026-09-03-cantera-notarium-asper.md`](audit/2026-09-03-cantera-notarium-asper.md) — qué de Notarium y asper-server merece subir al boilerplate: convergencias, reglas que cambian y propuesta v2.1 → v2.4

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
