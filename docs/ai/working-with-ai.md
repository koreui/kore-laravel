# Trabajar con la AI

**TL;DR**: Laravel Boost expone un MCP server con 15 herramientas (DB schema, queries, routes, artisan, tinker, logs, docs, etc.). `CLAUDE.md` y `AGENTS.md` (simétricos) cargan reglas en cada sesión. Skills propios en `.claude/skills/` automatizan tareas comunes (crear módulo, Action, componente Livewire).

## Archivos

| Archivo / carpeta              | Para qué                                            |
|--------------------------------|-----------------------------------------------------|
| `CLAUDE.md`                    | reglas globales — Claude Code lo lee al inicio      |
| `AGENTS.md`                    | mismas reglas, formato Codex/agnóstico              |
| `.mcp.json`                    | declara el MCP server `laravel-boost`               |
| `boost.json`                   | metadata de Boost                                   |
| `.claude/skills/`              | skills propios + skills de Boost (laravel, livewire, pennant, pest) |
| `.agents/skills/`              | skills agnósticos (mismo set, otro formato)         |
| `.codex/config.toml`           | config para Codex                                   |

## Laravel Boost MCP

El MCP server arranca con `php artisan boost:mcp`. La AI (Claude Code, Codex, Cursor, Junie) se conecta vía `.mcp.json` y obtiene 15 herramientas:

- `database-schema` — inspecciona tablas
- `database-query` — corre SELECTs read-only
- `routes` — lista rutas registradas
- `artisan` — ejecuta cualquier `php artisan ...`
- `tinker` — eval PHP en contexto de la app
- `logs` — lee `storage/logs/laravel.log`
- `browser-logs` — captura console del navegador
- `search-docs` — docs de Laravel y paquetes (17,000+ snippets versionados)
- `get-absolute-url`, `package-list`, etc.

**Regla**: la AI debe preferir `search-docs` antes de generar código que use APIs de paquetes. Esto evita inventar APIs por desactualización del modelo.

## CLAUDE.md / AGENTS.md

Contenido principal:

1. **Idioma**: español.
2. **Stack** y versiones.
3. **Arquitectura** resumida (modular monolith + actions).
4. **Toggles** del boilerplate.
5. **Componentes UI**: siempre `<x-kore::*>`.
6. **Reglas de oro**: strict_types, final, type hints, sin lógica gorda en controllers, etc.
7. **Comandos útiles**: `composer dev|test|ci|lint|analyse|refactor`.
8. **NO HACER**: lista clara de antipatrones.
9. **Antes de finalizar un cambio**: ejecutar Pint, Pest, PHPStan.

Después del `<laravel-boost-guidelines>`, Boost inyecta las guidelines oficiales (php, laravel/v12, livewire/core, pest/core, pint/core, etc.). Esa parte se regenera con `php artisan boost:install --guidelines` — no editar manualmente.

## Skills propios

Vive cada uno en `.claude/skills/{nombre}/SKILL.md` (también copiados a `.agents/skills/`).

| Skill                  | Cuándo                                                          |
|------------------------|------------------------------------------------------------------|
| `module-scaffold`      | el usuario pide "crear módulo X" / "scaffold dominio Y"          |
| `kore-action-create`   | el usuario pide implementar un caso de uso / mover lógica        |
| `kore-livewire-create` | el usuario pide componente reactivo / página interactiva         |

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
2. **Apunta a archivos por path absoluto**: `app/Modules/Auth/Actions/Fortify/CreateNewUser.php:23`.
3. **Pide ejecutar `composer ci`** antes de cerrar el cambio.
4. **No aceptes "lo apliqué"** sin ver el diff. Lee el código.
5. **Si la AI inventa una API**: hazle correr `search-docs` con los términos exactos.

## Cómo extender la capa AI

### Agregar un skill propio

```bash
mkdir -p .claude/skills/mi-skill
mkdir -p .agents/skills/mi-skill   # opcional, para Codex/etc.
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

Edita `.mcp.json` y agrega tu server. Ejemplo: agregar el MCP de kore-ui:

```json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "php",
      "args": ["artisan", "boost:mcp"]
    },
    "kore-ui": {
      "command": "node",
      "args": ["../koreUi/kore-ui-mcp/dist/index.js"]
    }
  }
}
```

### Refrescar guidelines de Boost

```bash
php artisan boost:install --guidelines --skills --mcp
```

Sobrescribe la sección `<laravel-boost-guidelines>` de `CLAUDE.md` y `AGENTS.md`. **NO** toca el contenido custom que agregamos arriba del bloque.

## Recursos

- Laravel Boost: https://laravel.com/ai/boost · https://laravel.com/docs/12.x/boost
- AGENTS.md spec: https://github.com/anthropics/agents.md
- Skills directory de la comunidad: https://laravel-news.com/laravel-skills
