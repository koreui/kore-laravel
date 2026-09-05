# Trabajar con la AI

**TL;DR**: dos MCP servers. Laravel Boost expone 11 herramientas sobre el *framework* (schema, queries, docs, logs, tinker, etc.) y `kore` expone 5 sobre *este proyecto* (módulos, toggles, permisos, reglas, `kore:arch:check`). `CLAUDE.md` es el archivo que se edita y `AGENTS.md` **se genera desde él** con `php artisan kore:agents:sync` (R50); los dos resumen las reglas y enlazan el catálogo [`docs/architecture/rules.md`](../architecture/rules.md), que es la fuente de verdad y numera cada regla como `R{n}`. Nueve skills viven en `.agents/skills/` —cinco propios y cuatro de Boost— y `.claude/skills/` son symlinks a esa carpeta (R49).

## Archivos

| Archivo / carpeta              | Para qué                                            |
|--------------------------------|-----------------------------------------------------|
| `CLAUDE.md`                    | reglas globales — Claude Code lo lee al inicio. **Es el que se edita.** |
| `AGENTS.md`                    | mismas reglas, formato Codex/agnóstico. **Generado** desde `CLAUDE.md` por `php artisan kore:agents:sync` (R50): no se edita a mano |
| `.mcp.json`                    | declara los MCP servers `laravel-boost`, `kore-ui` y `kore` |
| `routes/ai.php`                | registra los MCP servers propios (`Mcp::local('kore', …)`) |
| `boost.json`                   | metadata de Boost                                   |
| `.agents/skills/`              | **la carpeta real** de los nueve skills (5 propios + 4 de Boost), en el formato del estándar Agent Skills |
| `.claude/skills/`              | nueve **symlinks relativos** a `../../.agents/skills/{nombre}`, uno por skill (R49) |
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

## MCP propio: `kore`

Boost responde preguntas sobre **el framework**. `kore` responde preguntas sobre
**este proyecto**: qué módulos hay, quién lee un toggle, qué permisos existen,
qué dice `R24`. Sin él, cada una de esas respuestas cuesta abrir entre tres y
diez archivos, y el agente llega al código con el contexto ya gastado.

```bash
php artisan mcp:start kore        # lo arranca el cliente, no tú
php artisan mcp:inspector kore    # el inspector oficial, para depurarlo a mano
```

El servidor vive en `app/Core/Mcp/KoreServer.php`, sus herramientas en
`app/Core/Mcp/Tools/` y se registra en `routes/ai.php` —un archivo que
`laravel/mcp` carga solo si existe, sin tocar `bootstrap/app.php`—. Los clientes
lo declaran en `.mcp.json` y `.codex/config.toml`.

### Las cinco herramientas

| Herramienta | Para qué | Pregunta que resuelve |
|-------------|----------|-----------------------|
| `kore-list-modules` | inventario de `app/Modules/*`: provider, si está registrado en `bootstrap/providers.php`, carpetas de la lista cerrada (R3), nº de Actions, nº de componentes Livewire, rutas `web`/`api` y nº de tests | «¿existe ya un módulo para facturación?», «¿el módulo Users tiene rutas de API?», «¿cuántas Actions tiene Auth?» |
| `kore-list-toggles` | los toggles de `config/kore-app.php` con su variable de `.env`, su valor por defecto, su valor actual y **qué archivos los leen**; más las claves que encienden capacidades sin ser toggles (`pulse.enabled`, `sentry.dsn`, `health.secret_token`) | «¿qué apaga `TENANCY_ENABLED` exactamente?», «¿quién lee `kore-app.backup.enabled`?», «¿está Sentry configurado?» |
| `kore-list-permissions` | roles del sistema (`SystemRole`), roles asignables, la matriz de módulos con sus permisos `{slug}.{accion}`, los permisos de cada rol y —si la base responde— cuáles están sembrados. Todo vía `App\Core\Contracts\AuthorizationCatalog` | «¿qué permiso necesito para el listado de usuarios?», «¿qué le llega al rol `Usuario`?», «¿existe `invoices.approve`?» |
| `kore-get-rule` | una regla del catálogo por número (`R24`) con su enunciado, enforcement, severidad, válvula y cicatriz; sin parámetro, la tabla resumen de las 57 | «¿qué dice R24?», «¿qué válvula admite R20?», «dame el índice de reglas» |
| `kore-arch-check` | ejecuta `php artisan kore:arch:check` (opcionalmente `--rule` o `--files`) y devuelve salida y código de salida | «¿rompí algo con lo que acabo de escribir?» |

Las cinco están anotadas como `readOnlyHint` e `idempotentHint`.

### Qué NO hace

- **No ejecuta código arbitrario.** `kore-arch-check` corre exactamente un
  comando —`kore:arch:check`— y no acepta el nombre del comando por parámetro.
  En el momento en que lo aceptara dejaría de ser un linter y sería una shell
  remota. Para evaluar PHP está `tinker`, en Boost, que el usuario autoriza
  sabiendo lo que autoriza.
- **No consulta la base de datos**, salvo `kore-list-permissions`, que mira si
  los permisos están sembrados y **degrada a catálogo estático con un aviso** si
  la base no responde (un clon recién hecho, sin migrar, sigue pudiendo
  preguntar). Para el esquema y las queries está `database-schema` /
  `database-query`, en Boost.
- **No escribe nada.**
- **No devuelve secretos.** Cualquier clave cuyo nombre contenga `token`,
  `password`, `secret`, `key`, `dsn`, `passphrase` o `credential` se responde
  como `configurado` / `sin configurar`, nunca con su valor. Lo cubre un test.
- **No importa `App\Modules`.** El servidor vive en `App\Core` y R6 dice que
  Core no depende de ningún módulo: `kore-list-modules` lee el sistema de
  archivos y `kore-list-permissions` habla por el contrato de Core, igual que
  Users habla con Auth.

### Añadir una herramienta

1. `php artisan make:mcp-tool` o, a mano, una clase `final` en
   `app/Core/Mcp/Tools/` que extienda `Laravel\Mcp\Server\Tool`.
2. Cuatro cosas obligatorias:
   - `protected string $name = 'kore-…';` — sin esto el nombre lo deduce
     `Str::kebab(class_basename())` y sale `mi-tool-tool`.
   - `protected string $title` y `protected string $description` **en español**,
     y la descripción escrita para que un modelo sepa *cuándo* llamarla, no sólo
     qué devuelve.
   - `schema(JsonSchema $schema): array` si recibe parámetros.
   - `handle(Request $request): Response`, devolviendo `Response::json()` para
     datos y `Response::text()` para prosa (una regla, una salida de comando).
     Los errores esperables van con `Response::error()`, no con una excepción.
3. Anótala con `#[IsReadOnly]` / `#[IsIdempotent]` si lo es.
4. Regístrala en `$tools` de `app/Core/Mcp/KoreServer.php`.
5. Un test en `tests/Feature/KoreMcpTest.php`. Se usa el helper de `laravel/mcp`:

   ```php
   KoreServer::tool(MiTool::class, ['param' => 'valor'])
       ->assertOk()
       ->assertSee('lo que tiene que salir');
   ```

   Eso manda un `tools/call` real por un transporte falso, así que cubre nombre,
   esquema y serialización. No arranques `mcp:start` desde un test: ese comando
   se queda escuchando stdin para siempre.
6. Si la herramienta expone algo nuevo, actualiza la tabla de arriba en el mismo
   commit (R40).

### Depurarlo a mano

```bash
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"cli","version":"1.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | php artisan mcp:start kore
```

## El catálogo de reglas: `docs/architecture/rules.md`

Es **la fuente de verdad** del proyecto y el doc que más le importa a un agente.
`CLAUDE.md` y `AGENTS.md` sólo resumen y enlazan aquí.

Cuatro cosas que hay que saber antes de tocar código:

1. **Las reglas están numeradas `R1..R57` y se citan por número.** En un review,
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
   propio check (R40) sobre `app/`, `tests/`, `.agents/skills/`, los `*.neon` y
   `CLAUDE.md` / `AGENTS.md`. Citar un número inventado falla el build. (Los
   skills se barren una sola vez: desde la v1.4.0 `.claude/skills` son enlaces a
   `.agents/skills`, no una segunda copia.)
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

`AGENTS.md` **no se escribe**: se genera. Es una cabecera de aviso en comentario HTML
más `CLAUDE.md` íntegro debajo, y lo produce un comando:

```bash
php artisan kore:agents:sync          # regenera AGENTS.md desde CLAUDE.md
php artisan kore:agents:sync --check  # exit 1 si está desincronizado, sin escribir
```

Editas `CLAUDE.md`, corres el comando y commiteas los dos. Si te olvidas,
`composer arch` falla por R50 y te dice exactamente qué correr; el hook de
pre-commit hace lo mismo y **no** lo regenera por su cuenta, porque un hook que
escribe deja commiteado algo distinto de lo que revisaste.

La lógica de «qué debería contener AGENTS.md» vive en una sola clase,
`App\Core\Support\AgentsFile`, que usan el comando y el check.

Después del `<laravel-boost-guidelines>`, Boost inyecta las guidelines oficiales (php, laravel/v12, livewire/core, pest/core, pint/core, etc.). Esa parte se regenera con `php artisan boost:install --guidelines` — no editar manualmente.

## Skills propios

La carpeta real de cada skill es `.agents/skills/{nombre}/SKILL.md` —el formato
del estándar [Agent Skills](https://agentskills.io)— y `.claude/skills/{nombre}` es un
**symlink relativo** a `../../.agents/skills/{nombre}`. Uno por skill, no uno de
la carpeta padre: Claude Code sigue los enlaces a nivel de skill individual, pero
no el de la carpeta que los contiene.

El reparto no es arbitrario. Codex **no resuelve symlinks**, así que la carpeta
real tiene que ser la que él lee; Claude Code sí los sigue, así que su espejo se
resuelve solo. Git versiona los enlaces (modo `120000`), de modo que la
estructura viaja en el commit y en un clon nuevo no hay nada que reinstalar.

Los cinco skills propios declaran además `compatibility:` en su frontmatter —un
campo del estándar que Claude Code acepta e ignora— para que un cliente ajeno
sepa a qué stack pertenecen.

Hasta la v1.3.0 los skills estaban duplicados byte a byte y este doc pedía
mantenerlos con `cp -R` y `diff -r`. Eso es hoy R49, y lo verifica
`composer arch`.

| Skill                  | Cuándo                                                          | Reglas que cita |
|------------------------|------------------------------------------------------------------|-----------------|
| `module-scaffold`      | el usuario pide "crear módulo X" / "scaffold dominio Y"          | R1–R6, R8, R11, R13, R14, R23, R24, R29–R31, R44 |
| `kore-action-create`   | el usuario pide implementar un caso de uso / mover lógica        | R1, R2, R4, R5, R8, R13, R14, R19–R21, R35, R44 |
| `kore-livewire-create` | el usuario pide componente reactivo / página interactiva         | R4, R13–R15, R22–R24, R30–R32 |
| `kore-e2e-test`        | el usuario pide un spec de Playwright / probar un flujo en el navegador | R36–R39 |
| `kore-migration-change`| el usuario pide modificar una columna existente (`->change()`, `renameColumn`) | R3, R21, R29, R41, R44, R53 |

Que cada `R{n}` citada exista de verdad en el catálogo lo verifica `composer arch`
(R40) sobre `.agents/skills/`, que desde la v1.4.0 es el único set real.

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

El skill se escribe en `.agents/skills/`, que es la carpeta real, y se enlaza
desde `.claude/skills/`:

```bash
mkdir -p .agents/skills/mi-skill
$EDITOR .agents/skills/mi-skill/SKILL.md
ln -s ../../.agents/skills/mi-skill .claude/skills/mi-skill

php artisan kore:arch:check --rule=R49   # comprueba el enlace
```

Ojo con el `ln -s`: el destino es **relativo al enlace**, no al directorio desde
el que lo corres. `../../.agents/skills/mi-skill` es lo que R49 espera, letra por
letra; una ruta absoluta funciona en tu máquina y en ninguna otra.

`.agents/skills/mi-skill/SKILL.md`:

```markdown
---
name: mi-skill
description: Cuándo usarlo (la AI lee esto para decidir activarlo)
compatibility: "kore-laravel (Laravel 13, Livewire 4, Pest 5). Claude Code, Codex y cualquier cliente Agent Skills."
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
    "kore": {
      "command": "php",
      "args": ["artisan", "mcp:start", "kore"]
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

Si el servidor nuevo es **del propio proyecto** (una clase que extiende
`Laravel\Mcp\Server`), no basta con declararlo en los dos archivos de cliente:
hay que registrarlo también en `routes/ai.php` con `Mcp::local('handle', …)`,
que es lo que resuelve `php artisan mcp:start handle`.

### Refrescar guidelines de Boost

```bash
php artisan boost:install --guidelines --skills --mcp
```

Sobrescribe la sección `<laravel-boost-guidelines>` de `CLAUDE.md` y `AGENTS.md`. **NO** toca el contenido custom que agregamos arriba del bloque.

> `boost:install --guidelines` escribe en **los dos** archivos, incluido el
> generado. Después de correrlo, `php artisan kore:agents:sync` para que
> `AGENTS.md` vuelva a ser exactamente lo que sale de `CLAUDE.md` (si no,
> `composer arch` falla por R50).
>
> `boost:install --skills` escribe en `.claude/skills/`, que ahora son enlaces:
> comprueba con `php artisan kore:arch:check --rule=R49` que no ha dejado
> carpetas reales, y si lo ha hecho, mueve el contenido a `.agents/skills/` y
> rehaz el symlink.

## Recursos

- Laravel Boost: https://laravel.com/ai/boost · https://laravel.com/docs/12.x/boost
- AGENTS.md spec: https://agents.md
- Skills directory de la comunidad: https://laravel-news.com/laravel-skills
