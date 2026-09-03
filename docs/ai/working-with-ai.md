# Trabajar con la AI

**TL;DR**: Laravel Boost expone un MCP server con 11 herramientas (schema, queries, docs, logs, tinker, etc.). `CLAUDE.md` y `AGENTS.md` (simétricos) resumen las reglas y enlazan el catálogo [`docs/architecture/rules.md`](../architecture/rules.md), que es la fuente de verdad y numera cada regla como `R{n}` para poder citarla. Cuatro skills propios en `.claude/skills/` automatizan las tareas comunes (módulo, Action, componente Livewire, spec E2E).

## Archivos

| Archivo / carpeta              | Para qué                                            |
|--------------------------------|-----------------------------------------------------|
| `CLAUDE.md`                    | reglas globales — Claude Code lo lee al inicio      |
| `AGENTS.md`                    | mismas reglas, formato Codex/agnóstico              |
| `.mcp.json`                    | declara los MCP servers `laravel-boost` y `kore-ui` |
| `boost.json`                   | metadata de Boost                                   |
| `.claude/skills/`              | skills propios + skills de Boost (laravel, livewire, pennant, pest) |
| `.agents/skills/`              | **copia** del mismo set, para Codex y otros agentes |
| `.codex/config.toml`           | config para Codex — espejo de `.mcp.json`, sin rutas absolutas |

## Laravel Boost MCP

El MCP server arranca con `php artisan boost:mcp`. La AI (Claude Code, Codex, Cursor, Junie) se conecta vía `.mcp.json` y obtiene las **11 herramientas** que registra Boost 2.x (la lista viva está en `vendor/laravel/boost/src/Mcp/Boost.php`; si actualizas el paquete, mírala ahí antes que aquí):

| Herramienta | Para qué |
|-------------|----------|
| `application-info` | versiones de PHP, Laravel y paquetes del ecosistema |
| `browser-logs` | consola y errores del navegador |
| `database-connections` | conexiones configuradas y cuál es la de por defecto |
| `database-query` | SELECTs read-only contra la base |
| `database-schema` | tablas, columnas e índices |
| `get-absolute-url` | resuelve esquema, dominio y puerto del proyecto |
| `last-error` | la última excepción del log |
| `read-log-entries` | últimas entradas de `storage/logs/` |
| `record-rule` | guarda una regla aprendida para futuras sesiones |
| `search-docs` | documentación versionada de Laravel y de los paquetes instalados |
| `tinker` | evalúa PHP en el contexto de la app |

No hay herramientas `routes`, `artisan`, `logs` ni `package-list`: para rutas y comandos se usa la terminal (`php artisan route:list`), que es lo que dicen las guidelines de Boost inyectadas en `CLAUDE.md`.

**Regla**: la AI debe preferir `search-docs` antes de generar código que use APIs de paquetes. Esto evita inventar APIs por desactualización del modelo.

## El catálogo de reglas: `docs/architecture/rules.md`

Es **la fuente de verdad** del proyecto y el doc que más le importa a un agente.
`CLAUDE.md` y `AGENTS.md` sólo resumen y enlazan aquí.

Cuatro cosas que hay que saber antes de tocar código:

1. **Las reglas están numeradas `R1..R45` y se citan por número.** En un review,
   en un mensaje de commit, en un comentario del código o al pedirle algo a la
   AI, «esto rompe R24» es una frase completa. Cada regla lleva su enunciado,
   quién la verifica y con qué comando, la severidad, la válvula que admite, por
   qué existe y la cicatriz real que la originó.
2. **`composer arch` (`php artisan kore:arch:check`) es el verificador textual.**
   Cubre lo que ni PHPStan ni Pest ven: un `#[Locked]` que falta, un
   `authorize()` ausente, una migración sin `down()`, Eloquent en una Blade, un
   `data-testid`, un toggle que no lee nadie, un doc sin enlazar o una válvula
   caducada. Tarda ~0,2 s, así que corre también en el pre-commit. Para depurar
   uno solo: `php artisan kore:arch:check --rule=R29`.
3. **Toda `R{n}` que escribas tiene que existir en el catálogo.** Lo verifica el
   propio check (R40) sobre `app/`, `tests/`, los dos sets de skills, los
   `*.neon` y `CLAUDE.md` / `AGENTS.md`. Citar un número inventado falla el
   build.
4. **Las válvulas de escape no las escribe un agente.** Son dos, con gramática
   fija:

   ```php
   // arch-exception: R12 · razón breve · @owner · 2026-12-31   ← deuda con fecha
   // arch-accepted:  R20 · razón breve · @owner                ← decisión permanente
   ```

   No son intercambiables: cada regla declara en su `> Escape:` cuál admite, y
   `composer arch` rechaza la otra. Y **R44 dice que el agente nunca se aprueba
   una a sí mismo**: si Claude o Codex llega a un punto donde hace falta una, se
   detiene y pregunta, porque el `@owner` lo firma una persona —la que responde
   cuando la fecha vence—.

## CLAUDE.md / AGENTS.md

Contenido principal:

1. **Idioma**: español.
2. **Stack** y versiones.
3. **Arquitectura** resumida (modular monolith + actions) y el resumen numerado de las reglas, que enlaza el catálogo.
4. **Válvulas de escape** y **capas de verificación**.
5. **Toggles** del boilerplate.
6. **Componentes UI**: siempre `<x-kore::*>`.
7. **i18n** y **tests E2E**.
8. **Comandos útiles**: `composer dev|test|ci|lint|analyse|arch|refactor`.
9. **NO HACER**: lista clara de antipatrones.
10. **Antes de finalizar un cambio**: Pint, `composer arch`, Pest, PHPStan y —si tocaste UI— `npm run e2e`.

Los dos archivos tienen que quedar **idénticos** (`diff CLAUDE.md AGENTS.md`
vacío): se escribe uno y se copia.

Después del `<laravel-boost-guidelines>`, Boost inyecta las guidelines oficiales (php, laravel/v12, livewire/core, pest/core, pint/core, etc.). Esa parte se regenera con `php artisan boost:install --guidelines` — no editar manualmente.

## Skills propios

Vive cada uno en `.claude/skills/{nombre}/SKILL.md`, con una **copia byte a byte**
en `.agents/skills/{nombre}/SKILL.md`. Son copias y no symlinks porque Codex no
resuelve enlaces simbólicos dentro del repo. La contrapartida es que hay que
copiar a mano al editar un skill:

```bash
cp -R .claude/skills/mi-skill .agents/skills/
diff -r .claude/skills .agents/skills   # debe salir vacío
```

El roadmap de la auditoría
([`docs/audit/2026-09-02-auditoria-y-roadmap.md`](../audit/2026-09-02-auditoria-y-roadmap.md),
hito v1.4.0) propone unificarlas en una sola carpeta con frontmatter
`compatibility`. Hasta que eso ocurra, la copia es obligatoria.

| Skill                  | Cuándo                                                          | Reglas que cita |
|------------------------|------------------------------------------------------------------|-----------------|
| `module-scaffold`      | el usuario pide "crear módulo X" / "scaffold dominio Y"          | R1–R6, R8, R11, R13, R14, R23, R24, R29–R31, R44 |
| `kore-action-create`   | el usuario pide implementar un caso de uso / mover lógica        | R1, R2, R4, R5, R8, R13, R14, R19–R21, R35, R44 |
| `kore-livewire-create` | el usuario pide componente reactivo / página interactiva         | R4, R13–R15, R22–R24, R30–R32 |
| `kore-e2e-test`        | el usuario pide un spec de Playwright / probar un flujo en el navegador | R36–R39 |

Que cada `R{n}` citada exista de verdad en el catálogo lo verifica `composer arch`
(R40), y los skills entran en ese barrido.

Cada skill incluye:

- **Cuándo activarse** (frontmatter `description`).
- **Plantillas exactas** de código (no re-inventar).
- **Reglas del proyecto** aplicables (final classes, strict_types, naming).
- **Test mínimo** que debe acompañar el cambio.
- **Pasos finales**: `composer ci` para validar.

## Skills oficiales (de Laravel Boost)

Sincronizados al instalar Boost:

- `laravel-best-practices`
- `livewire-development`
- `pennant-development`
- `pest-testing`

Se actualizan con `php artisan boost:install --skills`.

## Cómo hacer cambios efectivos con la AI

1. **Carga contexto correcto**: pídele leer `CLAUDE.md` y el doc de área (`docs/modules/auth.md`, etc.). Nunca asumas que ya lo leyó.
2. **Apunta a archivos por path absoluto**: `app/Modules/Auth/Fortify/CreateNewUser.php:23`.
3. **Pide ejecutar `composer ci`** antes de cerrar el cambio (incluye `composer arch`).
4. **No aceptes "lo apliqué"** sin ver el diff. Lee el código.
5. **Si la AI inventa una API**: hazle correr `search-docs` con los términos exactos.
6. **Si te propone una válvula de escape, no la aceptes sin leerla.** R44 dice
   que el agente se para y pregunta; el `@owner` y la fecha los pones tú.

## Cómo extender la capa AI

### Agregar un skill propio

```bash
mkdir -p .claude/skills/mi-skill
# ...escribe el SKILL.md y luego replícalo (no es opcional: README y este doc
# afirman que los dos sets son idénticos, y hay que sostenerlo)
cp -R .claude/skills/mi-skill .agents/skills/
```

`.claude/skills/mi-skill/SKILL.md`:

```markdown
---
name: mi-skill
description: Cuándo usarlo (la AI lee esto para decidir activarlo)
---

# Título

## Reglas
...

## Plantilla
...
```

### Agregar un MCP server adicional

Edita `.mcp.json` **y** `.codex/config.toml`: las dos herramientas deben ver los
mismos servidores. El de kore-ui ya viene declarado y sirve de ejemplo:

```json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "php",
      "args": ["artisan", "boost:mcp"]
    },
    "kore-ui": {
      "type": "http",
      "url": "https://kore-ui-mcp.ovilla.dev/mcp"
    }
  }
}
```

El equivalente en `.codex/config.toml`:

```toml
[features]
experimental_use_rmcp_client = true   # servidores por HTTP

[mcp_servers.kore-ui]
url = "https://kore-ui-mcp.ovilla.dev/mcp"
```

Nada de `cwd` con rutas absolutas: Codex ya arranca desde la raíz del proyecto y
una ruta absoluta rompe el repo para cualquier otro clon.

### Refrescar guidelines de Boost

```bash
php artisan boost:install --guidelines --skills --mcp
```

Sobrescribe la sección `<laravel-boost-guidelines>` de `CLAUDE.md` y `AGENTS.md`. **NO** toca el contenido custom que agregamos arriba del bloque.

## Recursos

- Laravel Boost: https://laravel.com/ai/boost · https://laravel.com/docs/12.x/boost
- AGENTS.md spec: https://agents.md
- Skills directory de la comunidad: https://laravel-news.com/laravel-skills
