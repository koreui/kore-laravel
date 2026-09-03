# Auditoría de kore-laravel y roadmap (2 de septiembre de 2026)

> Consolidado de tres análisis: auditoría del código de kore-laravel, análisis del boilerplate
> `z-laravel-boilerplate` del compañero, e investigación del ecosistema Laravel a septiembre 2026.
> Todos los hallazgos marcados como **verificado** los confirmé yo directamente contra el código.

---

## TL;DR

- El pipeline está verde (Pint, Larastan L8, Rector, 32 tests) y el Docker de producción está bien pensado. Esa es la buena noticia.
- Hay **dos agujeros de autorización explotables** en el módulo Users, que es justo el CRUD de referencia.
- Hay una **brecha grande entre lo que docs/CLAUDE.md prometen y lo que el código hace**: el Action Pattern y los DTOs no existen en ninguna línea, Sentry no captura excepciones, `/health` no existe, el scheduler está vacío, y 7 toggles no los lee nadie.
- Como boilerplate **no hay versionado**: 0 tags, sin CHANGELOG, sin guía para actualizar proyectos derivados. Es el bloqueante estructural para el objetivo que tienes.
- El repo del compañero tiene peor producto (sin toggles, sin tenancy, sin Docker prod, PHPUnit en vez de Pest) pero **mucha mejor disciplina**: reglas numeradas verificadas por el build, PHPat, `phpstan-disallowed-calls`, tests de instalación limpia y de migraciones reversibles, cabeceras de seguridad, backups con monitor. Eso es lo que vale la pena importar.

---

## 1. Estado real de kore-laravel

### 1.1 Lo que está bien

- `declare(strict_types=1)` en el 100 % de los PHP, type hints completos, `CarbonImmutable` forzado.
- `RateLimiter` para login y 2FA, `Gate::before` para superadmin, `URL::forceHttps()` en prod, `Model::shouldBeStrict()` fuera de prod.
- Nginx dockerizado con CSP, HSTS, `X-Frame-Options`, `Permissions-Policy`, bloqueo de `.env`/`.git`, `server_tokens off`, rate limit en rutas de auth.
- MySQL y Redis sin puertos públicos, secrets de Docker, healthchecks en db/redis, opcache con JIT, queue worker con `--max-time`.
- Toggles que sí funcionan: `API_ENABLED`, `TENANCY_ENABLED`, `AUTH_MAGIC_LINKS`, `AUTH_SOCIAL_LOGIN`, `SOCIAL_GOOGLE/GITHUB`.

### 1.2 Seguridad (verificado)

| Sev. | Hallazgo | Archivo |
|---|---|---|
| 🔴 | `confirmDelete()` solo comprueba que no sea el propio usuario. No verifica `users.delete` ni superadmin. El bloqueo está solo en el `hidden()` del RowAction, que es cliente. Alguien con `users.view` puede borrar a un superadmin. | `app/Modules/Users/Http/Livewire/TableUsers.php:111` |
| 🔴 | `UserForm::$id` es `public ?int` **sin `#[Locked]`** y `FormComponent::save()` no llama a `authorize()`. Con `users.create` se puede fijar `form.id` a otro usuario y `updateOrCreate` sobrescribe email, password, rol y permisos. Escalada directa. Agravado por `Model::unguard()` global. | `app/Modules/Users/Forms/UserForm.php:74`, `FormComponent.php:45` |
| 🟠 | Grupo `api` sin `throttle:api` y sin `RateLimiter::for('api')`, con `API_ENABLED=true` por defecto. | `bootstrap/app.php` |
| 🟠 | `MagicLink::sendCode()` sin rate limit y con `exists:users,email`: enumeración de usuarios y envío ilimitado de correos. El rate limit de nginx cubre `/magic-link` pero no `/livewire/update`. | `app/Modules/Auth/Http/Livewire/MagicLink.php:20` |
| 🟠 | Sin `trustProxies`: detrás del Nginx del contenedor `$request->ip()` siempre es la IP interna. Rate limiters por IP, logs y Sentry pierden la IP real. | `bootstrap/app.php` |
| 🟡 | `/pulse` sin `Gate::define('viewPulse')` y con `PULSE_ENABLED` default real `true`. En prod nadie entra; en local está encendido aunque README diga `false`. | `config/pulse.php:57,134` |

La `UserPolicy` existe, está registrada y es correcta, pero **ningún componente Livewire la invoca**. Este es el mismo agujero que el compañero resolvió con su regla "`#[Locked]` + `authorize()` dentro del componente, porque la petición viaja a `/livewire/update` y el middleware `permission:` de la ruta no corre".

### 1.3 Promesas vs realidad (verificado)

| Promesa | Realidad |
|---|---|
| Sentry captura excepciones | **No.** `withExceptions()` vacío y sin canal `sentry` en logging. sentry-laravel 4.25 exige `Integration::handles($exceptions)`. |
| `/health` y `/health/json` siempre disponibles | **No existen.** `HealthServiceProvider` registra checks, nadie registra la ruta. El runbook de deploy hace `curl .../health/json`. |
| Health checks se ejecutan | **No.** `schedule:list` vacío. `ScheduleCheck` fallaría siempre. El servicio `scheduler` de Docker no hace nada. |
| `REVERB_ENABLED`, `OCTANE_ENABLED`, `SCOUT_ENABLED` | Paquetes **no instalados**, claves nunca leídas (salvo cosmético en `artisan about`). |
| `TENANCY_MODE`, `SENTRY_ENABLED`, `kore-app.auth.two_factor`, `kore-app.observability.*` | Declarados, **nunca leídos**. 2FA funciona por `config/fortify.php` con `env()` directo. |
| Action Pattern con `handle()` | **0 Actions** extienden `Core\Actions\Action`. Las 5 de `Auth/Actions/Fortify` son contratos de Fortify. |
| DTOs con spatie/laravel-data | **0 clases** extienden `Core\Data\Data`. `Auth/Data/` está vacío y sin trackear. |
| `Core/Contracts`, `Core/Support`, `Core/Concerns` | Solo `.gitkeep`. |
| ActivityLog | Tabla creada, **0 modelos** con `LogsActivity`. |
| Pennant | Sin `Feature::define`, sin `config/pennant.php`, **sin migración `features`**: `Feature::active()` reventaría. |
| Arch tests (README, CLAUDE.md) | **0** llamadas `arch()`. `pipeline.md` los lista como futuro. |
| Horizon (`overview.md`) | No instalado. |
| `.agents/skills` = mismo set que `.claude/skills` | Faltan los 3 skills propios en `.agents/`. Los 4 de Boost están duplicados byte a byte. |
| Users cumple reglas de oro | **No.** Lógica en `UserForm::store()`, imports cruzados de `Auth\Models\{Role,Module}` en 5 archivos, Eloquent directo en `dashboard.blade.php:22`. |

### 1.4 Ops y producción

- **Sin backups** de ningún tipo (paquete, servicio ni doc). Mayor hueco de ops.
- `storage/logs` no está en ningún volumen: los logs se pierden al recrear el contenedor. Sin rotación de json-file en compose.
- Entrypoint corre `migrate --force` en el contenedor que sirve tráfico y **no hace `queue:restart`** tras deploy.
- Sin healthcheck en nginx, sin `deploy.resources`, sin tuning de php-fpm (`pm.max_children`).
- `HEALTH_OAUTH_TOKEN` en `.env.example` no coincide con `HEALTH_SECRET_TOKEN` que lee `config/health.php`.
- `robots.txt` permite indexar todo, incluido `/pulse` y `/users`.

### 1.5 Mantenimiento como boilerplate

- **0 tags**, sin `CHANGELOG.md`, `UPGRADE.md`, `CONTRIBUTING.md`, sin campo `version`.
- **Ninguna forma de actualizar un proyecto derivado** desde el upstream.
- `.github/` solo tiene `ci.yml`. Sin Dependabot/Renovate, CODEOWNERS, templates, release ni deploy workflow.
- CI no hace `npm ci && npm run build`: un build de Vite roto pasa verde (los tests usan `withoutVite()`).
- `CLAUDE.md` y `AGENTS.md` son copias idénticas de 345 líneas, no symlink.
- `.codex/config.toml` tiene `cwd` absoluto a tu máquina y no declara el MCP de kore-ui.
- README enlaza `[koreUi](../koreUi)` (roto en GitHub). Docs con cifras viejas (15 tests vs 32, Auth 10 vs 17, `bootstrap/providers.php` sin Users).

### 1.6 Versiones (salida real de `composer outdated`)

| Paquete | Actual | Disponible |
|---|---|---|
| laravel/framework | 12.68 | **13.30** |
| pestphp/pest | 3.8 | **5.1** |
| phpunit/phpunit | 11.5 | **13.3** |
| spatie/laravel-permission | 7.4 | **8.3** |
| laravel/tinker | 2.11 | 3.0 |
| vite (npm) | 7.3 | **8.2** |
| laravel-vite-plugin | 2.1 | 3.2 |

Minors pendientes: fortify 1.39, pulse 1.8, larastan 3.11, rector 2.6, pint 1.30 (formatea Blade), boost 2.7, sentry 4.27, tailwind 4.3.

Contexto del ecosistema: Laravel 13 salió en marzo 2026 con pocos breaking changes (PHP 8.3–8.5, `PreventRequestForgery`, atributos `#[Middleware]`/`#[Authorize]`, passkeys nativas en Fortify vía `laravel/passkeys`). Pest 4+ trae presets de arquitectura (`php()`, `security()`, `laravel()`) y browser testing. Pint 1.30 formatea Blade. Livewire sigue en 4, Tailwind en 4, Alpine en 3. `stancl/tenancy` v4 existe pero su compatibilidad con Laravel 13 no la pude verificar.

---

## 2. Qué aporta z-laravel-boilerplate

Ficha: Laravel 13, PHP ^8.5, Livewire 4, TallStackUI 4, PostgreSQL, PHPUnit 13 (rechaza Pest explícitamente), DDEV para dev, sin Docker de producción, sin toggles, sin tenancy. Tag `v3.0.0`. README y catálogo de patterns desactualizados tras su migración a módulos.

### 2.1 Copiar (ordenado por valor/esfuerzo)

1. **Reglas de arquitectura numeradas** (`docs/ARCHITECTURE_RULES.md`, R1–R58). Cada regla tiene enunciado, `Enforcement: herramienta · comando · severidad`, `Escape:` y la cicatriz que la originó. Tus "reglas de oro" en prosa se convierten en `R1..Rn` citables en review y, sobre todo, atables a un verificador.
2. **PHPat dentro de PHPStan** (`tests/Arch/ArchitectureRules.php` como servicio `phpat.test` en `phpstan.neon`). Un solo binario que falla el build si `Modules/X` importa `Modules/Y`, si una Action no es `final` con un solo `handle()`, si un DTO no es `readonly`. Hoy kore confía en que la IA respete la regla; esto la hace ejecutable.
3. **`spaze/phpstan-disallowed-calls`** con listas blancas por ruta: prohíbe `auth()`/`request()`/`session()` en `Actions/` y `Models/` (en un job devuelven null en silencio), `abort_if` fuera de `Policies/`, `DB::table()` fuera de migraciones. Encaja directo con tus reglas 2 y 3.
4. **Válvulas de escape con gramática fija**: `arch-exception: R13 · razón · @owner · 2026-12-31` (el lint falla cuando vence) vs `arch-accepted: R52 · razón · @owner`. Y la regla "el agente nunca se pone de dueño: si necesita una excepción, se detiene y pregunta". Va directo a CLAUDE.md.
5. **Baseline de PHPStan con fecha de caducidad** (`# arch-baseline: vence YYYY-MM-DD`).
6. **`SecurityHeaders` middleware + `config/security.php`** con CSP en bloqueo y `CSP_REPORT_ONLY` para introducirla sin romper, `trustProxies` explicado. Tú ya tienes las cabeceras en nginx, pero moverlas a la app hace que funcionen en cualquier hosting y sean testeables (`CabecerasDeSeguridadTest`).
7. **`refuseToBootWithDebugInProduction()`** en AppServiceProvider + test. Cinco líneas.
8. **`MigrationsAreReversibleTest`** (`migrate:fresh` → `rollback --step=100` → `migrate`) y check de que todo `down()` exista.
9. **`CleanInstallTest`** (`migrate:fresh` + `db:seed` reales, el camino que `RefreshDatabase` nunca recorre). Con módulos y seeders propios tu riesgo es idéntico.
10. **Reglas anti-escalada** `GrantablePermission` / `GrantableRole` ("nadie reparte un permiso que no tiene") + 11 tests de `EscaladaDePrivilegiosTest`. Son la plantilla exacta para cerrar tus dos 🔴.
11. **Disciplina `#[Locked]` + `authorize()` en Livewire** verificada por lint.
12. **`spatie/laravel-backup` con la tríada** `backup:run` + `clean` + `monitor`, zip cifrado, avisos solo de fallo, y test de que el monitor vigila el mismo destino.
13. **`composer audit` + `npm audit` en CI**, el segundo `continue-on-error` con la razón escrita.
14. **`.agents/skills/` como carpeta única** con `compatibility: claude_code, codex, cursor, opencode` en frontmatter, en vez de duplicar entre `.claude/` y `.agents/`.
15. **MCP propio de introspección** (`app/Mcp/Servers/BoilerplateServer.php`): para kore sería "lista módulos y toggles", "estado de `kore-app.php`".
16. **Rate limiting dentro de Livewire** (`InteractsWithRateLimiting`), honeypot en registro, anti-enumeración en reset.
17. **i18n por módulo** (`Resources/lang/{es,en}` dentro de cada módulo) + middleware `SetLocale` con prioridad sesión → perfil → cookie → config. Tú tienes 171 `__()` con literales en español y `APP_LOCALE=en`.
18. **Docs servidos en `/docs`** dentro de la app leyendo `docs/*.md`. Tú ya enlazas `/docs` desde el landing y da 404.
19. **`docs/patterns/` con regla de tres**: a la 3.ª repetición se generaliza y sube al boilerplate; una mejora en un hijo vuelve al padre.
20. **Verificación en capas con presupuesto de tiempo** (pre-commit ~1 s, pre-push ~20 s, `make check` ~60 s, CI ~2 min). Tú ya tienes `laravel-git-hooks`; copia la tabla, no la ausencia de instalador.

### 2.2 No copiar

- PHPUnit en lugar de Pest. Copia *qué* prueban, no el runner.
- TallStackUI. Ni discutirlo.
- PHP `^8.5` / Laravel `^13` duros sin matriz. Tu matriz 8.3/8.4 es mejor para un boilerplate que otros instancian en VPS reales.
- Ausencia de toggles. Tu patrón de provider con `return` temprano es estrictamente superior.
- Rechazo de spatie/laravel-data. Ya estandarizaste `Core\Data\Data`; churn sin beneficio.
- DDEV como único entorno y prod sin contenedores.
- Su CSP lleva `'unsafe-eval'` y `'unsafe-inline'` (precio de Alpine): protege contra scripts de otro origen, no contra XSS inyectado. Copiar el mecanismo sin creer que compra más.
- Su skill `git-commits` prohíbe `Co-Authored-By`.
- Su `HasTable` ordena por cualquier columna que mande el navegador sin lista blanca.

---

## 3. Roadmap propuesto (versionado)

La idea es que cada release cierre un tema y que un proyecto derivado pueda seguir el CHANGELOG para saber qué aplicar. Semver: `1.0.0` marca "lo que hay hoy, corregido"; los majors se reservan para Laravel 13 / Pest 5.

### v1.0.0 — Cerrar la brecha promesa/realidad (1 semana)

Seguridad:
- `#[Locked]` en `UserForm::$id`; `authorize()` en `save()` y `confirmDelete()`.
- `throttle:api` + `RateLimiter::for('api')`; rate limit en `MagicLink::sendCode()` con mensaje genérico.
- `trustProxies` en `bootstrap/app.php`.
- Quitar `Model::unguard()` global (o justificarlo por escrito).
- `Gate::define('viewPulse')`, `PULSE_ENABLED` default `false` real.

Observabilidad conectada:
- `Integration::handles($exceptions)` de Sentry + canal `sentry` en logging.
- Ruta `/health` y `/health/json` protegidas por token; programar `health:check`, `health:schedule-check-heartbeat`, `pulse:check`, `queue:prune-*` en `routes/console.php`.
- `LogsActivity` en `User` y `Role` como ejemplo.
- Publicar config y migración de Pennant, o quitar el paquete.

Higiene:
- Borrar toggles fantasma (`REVERB`, `OCTANE`, `SCOUT`, `TENANCY_MODE`, `SENTRY_ENABLED`, `kore-app.auth.two_factor`, `observability.*`) o marcarlos "reservado, sin implementar" en `toggles.md`. Que README, CLAUDE.md y toggles.md digan lo mismo.
- Corregir `HEALTH_OAUTH_TOKEN` → `HEALTH_SECRET_TOKEN`, `../koreUi`, `/docs` 404, cifras de tests en docs, `bootstrap/providers.php` del module-pattern.
- Quitar `ExampleTest` ×2, `toBeOne()`, `something()`, `withoutVite()` duplicado, rule Rector duplicada, vistas vendor OTP/Pulse sin usar.
- Actualizar minors (fortify, pulse, larastan, rector, pint 1.30 con Blade, boost 2.7, sentry, tailwind).

Versionado:
- `CHANGELOG.md` (Keep a Changelog), tag `v1.0.0`, `LICENSE` ya está.
- `dependabot.yml` (composer + npm, semanal, agrupado) o Renovate.
- Job de assets en CI (`npm ci && npm run build`) y `composer audit`.

### v1.1.0 — El boilerplate se demuestra a sí mismo (1–2 semanas)

- Refactorizar Users al patrón declarado: `UserCreateAction`, `UserUpdateAction`, `UserDeleteAction` con `handle()` extendiendo `Core\Actions\Action`, `UserData` extendiendo `Core\Data\Data`. Que `UserForm::store()` solo delegue.
- Resolver el import cruzado Users → Auth: mover `Role`/`Module` a `Core` o exponer contrato en `Core/Contracts`.
- Dashboard como componente Livewire con `#[Computed]`, sin Eloquent en Blade.
- `Database/Factories` por módulo; `Http/Requests` cuando aplique.
- Reglas anti-escalada `GrantablePermission` / `GrantableRole` + tests de escalada.
- `lang/es` y `lang/en` propios; i18n por módulo.

### v1.2.0 — Disciplina verificable (1–2 semanas)

- `docs/architecture/rules.md` con reglas numeradas R1..Rn, enforcement y escape.
- Pest arch tests reales (`arch()`): sin imports cruzados, Actions `final` con `handle()`, DTOs `readonly`, `strict_types`, sin `dd()`/`env()` fuera de config.
- PHPat en `phpstan.neon` para lo que Pest arch no cubra.
- `phpstan-disallowed-calls`: `auth()`/`request()` en Actions y Models, `abort_*` fuera de Policies, `DB::table` fuera de migrations.
- Script `scripts/arch-lint.sh` (o comando `kore:arch:check`) para checks textuales rápidos: `#[Locked]` en ids de forms Livewire, `authorize()` en métodos de escritura, `down()` en migraciones.
- Tabla de capas de verificación con presupuesto de tiempo, enganchada a `laravel-git-hooks` (pre-commit: pint --dirty + arch-lint; pre-push: phpstan + pest).
- Válvulas `arch-exception` / `arch-accepted` documentadas en CLAUDE.md, con la regla de que el agente no se auto-aprueba excepciones.
- Baseline de PHPStan con fecha de caducidad (si algún día hace falta baseline).

### v1.3.0 — Producción completa (1 semana)

- `spatie/laravel-backup` como toggle `BACKUP_ENABLED` con `run` + `clean` + `monitor`, zip cifrado, destino S3 opcional, doc de restore en `deployment.md`, y test de que el monitor vigila el destino configurado.
- `SecurityHeaders` middleware + `config/security.php` con CSP report-only por env, tests de cabeceras. Nginx sigue añadiendo las suyas; la app deja de depender del hosting.
- `refuseToBootWithDebugInProduction()` + test de configuración de producción.
- `MigrationsAreReversibleTest` y `CleanInstallTest`.
- Logs a `stderr` en prod, `logging.options.max-size` en compose, healthcheck de nginx, `www.conf` de php-fpm, `queue:restart` en el entrypoint tras migrar, `robots.txt` con `Disallow` para `/pulse`, `/health`, `/users`.

### v1.4.0 — DX y AI tooling (3–5 días)

- Una sola carpeta de skills (`.agents/skills/`) con frontmatter `compatibility`, symlink desde `.claude/skills/`. `AGENTS.md` como symlink de `CLAUDE.md` o generado.
- `.codex/config.toml` sin `cwd` absoluto y con el MCP de kore-ui.
- MCP propio `kore:mcp` con tools: listar módulos y toggles, estado de `kore-app`, permisos registrados.
- Visor `/docs` dentro de la app (toggle `DOCS_ENABLED`, solo `local` por defecto).
- `docs/patterns/` con regla de tres.
- `docs/ops/upgrading-from-boilerplate.md`: receta `git remote add kore <url>` + `git fetch kore --tags` + `git merge v1.x` (o `cherry-pick` por release), lista de archivos que un derivado suele haber tocado (`.env.example`, `kore-app.php`, `providers.php`, `composer.json`) y cómo reconciliarlos.
- PR template, issue templates, CODEOWNERS, release workflow (release-please sobre conventional commits, que ya usas).

### v2.0.0 — Laravel 13 + Pest 5 (cuando v1.x esté estable)

- Laravel 12 → 13 (pocos breaking changes; revisar prefijo de cache/sesión y drenar colas antes de desplegar).
- Pest 3 → 5, PHPUnit 13, presets `arch()->preset()->php()/security()/laravel()`.
- spatie/laravel-permission 8, tinker 3, Vite 8 + laravel-vite-plugin 3.
- Passkeys vía `laravel/passkeys` + `Features::passkeys()` de Fortify como toggle `AUTH_PASSKEYS`, con pantalla (el compañero lo dejó a medias).
- `PreventRequestForgery`, atributos `#[Middleware]`/`#[Authorize]` donde simplifiquen.
- Evaluar `stancl/tenancy` v4 solo si confirma soporte de Laravel 13.
- Matriz de CI PHP 8.3 / 8.4 / 8.5.

### Fuera de scope salvo que un proyecto lo pida

Reverb, Octane, Scout, Horizon, Nightwatch, Laravel Cloud, AI SDK, Filament, Cashier, cookie consent, sitemap. Mejor tenerlos como **módulos opcionales documentados** (o skills que los instalen) que como toggles fantasma.

---

## 4. Estrategia de versionado y actualización de derivados

- **Semver + tags anotados + CHANGELOG** por release, generado con release-please a partir de tus conventional commits.
- **Derivados como forks reales**: cada proyecto nuevo nace de `composer create-project` o clone, y guarda `git remote add kore <repo>`. Actualizar = `git fetch kore --tags && git merge vX.Y.Z` y resolver conflictos guiados por `UPGRADE.md` de esa release.
- **UPGRADE.md por major** con pasos manuales (qué archivos de config republicar, qué migraciones nuevas, qué toggles cambiaron).
- **Consolidar migraciones por major** (como hace el compañero con R35) para que un derivado nuevo no arrastre 40 migraciones históricas.
- No existe un "cruft" para PHP. Si algún día quieres automatizar más, un comando `kore:upgrade --from=v1.2.0` que aplique el diff del boilerplate sobre rutas conocidas sería un diferenciador, pero primero hay que tener tags.

---

## 5. Hallazgos completos priorizados

| # | Sev. | Hallazgo | Archivo |
|---|---|---|---|
| 1 | 🔴 | `confirmDelete()` sin autorización | `Users/Http/Livewire/TableUsers.php:111` |
| 2 | 🔴 | `UserForm::$id` sin `#[Locked]`, `save()` sin `authorize()` | `Users/Forms/UserForm.php`, `FormComponent.php:45` |
| 3 | 🔴 | Sentry no captura excepciones | `bootstrap/app.php:25` |
| 4 | 🔴 | Sin backups | compose, `deployment.md` |
| 5 | 🟠 | `/health` no existe, docs y runbook lo usan | `HealthServiceProvider`, `observability.md`, `deployment.md` |
| 6 | 🟠 | Scheduler vacío, `ScheduleCheck` fallaría siempre | `routes/console.php` |
| 7 | 🟠 | API sin throttle | `bootstrap/app.php` |
| 8 | 🟠 | 0 tags, sin CHANGELOG ni guía de upgrade | raíz |
| 9 | 🟠 | Sin `trustProxies` | `bootstrap/app.php` |
| 10 | 🟠 | Magic link sin throttle + enumeración | `Auth/Http/Livewire/MagicLink.php:20` |
| 11 | 🟠 | Logs sin volumen ni rotación | `docker-compose.prod.yml` |
| 12 | 🟡 | 7 toggles fantasma | `config/kore-app.php` |
| 13 | 🟡 | `PULSE_ENABLED` default real `true`, `/pulse` sin gate | `config/pulse.php` |
| 14 | 🟡 | Action Pattern y DTOs inexistentes | `app/Core`, módulos |
| 15 | 🟡 | Users importa `Auth\Models` en 5 archivos | `Users/*` |
| 16 | 🟡 | Pennant sin config ni migración | `config/`, migrations |
| 17 | 🟡 | `.agents/skills` sin los 3 skills propios | `.agents/skills/` |
| 18 | 🟡 | `.codex/config.toml` con `cwd` absoluto | `.codex/config.toml` |
| 19 | 🟡 | Eloquent en Blade | `Auth/.../dashboard.blade.php:22` |
| 20 | 🟡 | CI sin build de assets ni `composer audit` | `.github/workflows/ci.yml` |
| 21 | 🟡 | Sin Dependabot, templates, release workflow; 6 majors pendientes | `.github/` |
| 22 | 🟢 | `/docs` 404, `../koreUi`, placeholder github.com | `welcome.blade.php`, `public.blade.php`, `README.md` |
| 23 | 🟢 | Docs con cifras y referencias obsoletas | `pipeline.md`, `auth.md`, `overview.md`, `observability.md`, `module-pattern.md` |
| 24 | 🟢 | `HEALTH_OAUTH_TOKEN` ≠ `HEALTH_SECRET_TOKEN` | `.env.example`, `config/health.php` |
| 25 | 🟢 | Restos de scaffold y carpetas vacías sin `.gitkeep` (`Auth/Data`, `Tenancy/Database/Migrations`) | varios |
