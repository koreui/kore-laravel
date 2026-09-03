# Pipeline de calidad

**TL;DR**: Pint formatea, Larastan nivel 8 analiza los tipos, PHPat vigila el grafo de dependencias, `phpstan-disallowed-calls` prohíbe llamadas concretas, `kore:arch:check` hace los checks textuales, Rector refactoriza y Pest 5 testea. `igorsgm/laravel-git-hooks` reparte el trabajo entre pre-commit, commit-msg y pre-push, y GitHub Actions lo corre todo en cada PR y publica el release desde el CHANGELOG. El comando único es `composer ci`.

El catálogo de reglas —qué se verifica, con qué herramienta y con qué severidad— vive en [`../architecture/rules.md`](../architecture/rules.md). Este documento explica cómo está montado el pipeline; aquel dice qué comprueba.

Los tests end-to-end en navegador van aparte, con Playwright y su propio workflow: ver [`e2e.md`](e2e.md).

## Comandos

```bash
composer test              # Pest
composer test:parallel     # Pest --parallel
composer test:coverage     # Pest --coverage --min=80
composer lint              # Pint (aplica fixes)
composer lint:test         # Pint --test (no fix)
composer analyse           # Larastan + PHPat + disallowed-calls (un solo binario)
composer arch              # kore:arch:check (checks textuales: R11, R23, R24, R29, R30, R37, R38, R40, R44, R45, R49, R50, R52)
composer refactor          # Rector (aplica)
composer refactor:test     # Rector --dry-run
composer ci                # lint:test + analyse + arch + refactor:test + test

php artisan kore:agents:sync             # regenera AGENTS.md desde CLAUDE.md (R50)
php artisan kore:agents:sync --check     # exit 1 si está desincronizado, sin escribir
php artisan kore:changelog:section v1.4.0  # la sección del CHANGELOG de una release (R42)
```

## Capas de verificación

Cada capa tiene un presupuesto de tiempo. Si una se pasa, se mueve trabajo a la
siguiente: un pre-commit lento se acaba saltando con `--no-verify`, y entonces
no verifica nada.

| Capa | Presupuesto | Medido | Qué corre |
|------|-------------|--------|-----------|
| **pre-commit** | ~2 s | **0,7 s** | `pint --dirty` + `kore:arch:check --files=<staged>` |
| **commit-msg** | ~1 s | **0,3 s** | `ConventionalCommitMsgHook` — el asunto sigue Conventional Commits (R43) |
| **pre-push** | ~30 s | **5 s** | `phpstan` (Larastan + PHPat + disallowed-calls) + `pest --parallel` |
| **`composer ci`** | ~90 s | **16 s** | `pint --test` (0,2 con caché, 2,6 en frío) + `phpstan` (0,8 con caché, 2,3 en frío) + `composer arch` (0,2) + `rector --dry-run` (3,2 con caché) + `pest` (11,2, secuencial) |
| **CI (GitHub)** | ~3 min | — | `composer ci` en matriz PHP 8.4 / 8.5 + `composer audit` + `npm ci && npm run build` + E2E |
| **Release (GitHub)** | — | — | sólo al empujar un tag `v*`: `kore:changelog:section` + `softprops/action-gh-release` |

Medido en un MacBook (Apple Silicon, PHP 8.4) con el repositorio de la v2.2.0 y
570 tests Pest. La suite E2E —163 tests en 18 archivos— va aparte y tarda
31 s en local. Los 59 tests nuevos de la v2.2.0 son los del contrato de la API
(`tests/Feature/Api`, 51), los cuatro de R54 en `ArchitectureTest` y los cuatro
que ganó `ApiTokenTest` al pasar el endpoint de usuario por el envelope.

> Nota de entorno: `composer test` limpia la config cacheada antes de correr
> Pest a propósito. Con un `bootstrap/cache/config.php` viejo, `PulseAccessTest`
> renderiza el dashboard de Pulse entero y un PHP con `memory_limit=128M` se
> queda sin memoria. Si lanzas `./vendor/bin/pest` a pelo y ves un fatal en
> `Pulse.php`, es eso: `php artisan config:clear`.

## Configuraciones

### Pint — `pint.json`

Preset Laravel + reglas extra:

- `declare_strict_types`: header obligatorio en cada PHP (R13)
- `date_time_immutable`: prefiere `DateTimeImmutable` sobre `DateTime` (R16)
- `fully_qualified_strict_types`: imports completos en docblocks
- `modernize_types_casting`: `(int)` en lugar de `intval()`
- `ordered_imports` (alfa)
- `single_quote`: comillas simples por default
- `ordered_class_elements`: orden estable de miembros de clase

### PHPStan — `phpstan.neon`

Un solo binario carga cuatro cosas:

```yaml
includes:
    - ./vendor/larastan/larastan/extension.neon        # tipos + magia de Laravel
    - ./vendor/phpat/phpat/extension.neon              # reglas de arquitectura
    - ./vendor/spaze/phpstan-disallowed-calls/extension.neon
    - ./phpstan-disallowed.neon                        # la lista concreta del boilerplate

parameters:
    level: 8
    paths:
        - app
        - tests/Arch/PhpatArchitecture.php
    excludePaths:
        analyseAndScan:
            - app/Modules/*/Database/Migrations/*
            - app/Modules/*/Tests/**/*
    phpat:
        ignore_built_in_classes: true
        show_rule_names: true

services:
    -
        class: Tests\Arch\PhpatArchitecture
        tags: [phpat.test]
```

Los tests de Pest se excluyen porque su sintaxis funcional confunde a PHPStan
(no extienden una clase; las expectativas llegan por `pest()->extend()` en
runtime). La excepción es `tests/Arch/PhpatArchitecture.php`, que es una clase
PHP normal y **tiene** que estar en `paths` para que PHPStan pueda reflejarla.

### PHPat — `tests/Arch/PhpatArchitecture.php`

Reglas de arquitectura escritas como reglas de PHPStan. Ven lo que Pest arch no
ve: el grafo de dependencias completo (parámetros, retornos, `new`, `catch`,
atributos, docblocks).

| Método | Regla |
|--------|-------|
| `testCoreNoDependeDeNingunModulo` | R6 |
| `testModulosNoSeImportanEntreSi` | R5 — genera una regla por **cada par** de módulos a partir de `glob(app/Modules/*)`, ignorando `Tests` y `Events` del módulo destino (los eventos son la frontera pública) |
| `testElDominioNoDependeDeLaCapaDeEntrega` | R4 · R19 — `Actions`, `Data`, `Rules`, `Models` no dependen de `Livewire\*`, `Illuminate\Http\Request` ni de `Modules\*\{Http,Forms}` |
| `testLosDtosSoloDependenDeDatos` | R8 — `canOnly()->dependOn()`: sólo `Core\Data`, `Core\Enums`, `Spatie\LaravelData` y enums |
| `testLosContractsDeCoreSonInterfaces` | R7 |
| `testLasActionsExponenUnSoloHandle` | R1 — `haveOnlyOnePublicMethodNamed('handle')` |

Un módulo nuevo queda cubierto sin tocar el archivo: las reglas de R5 se generan
desde el sistema de archivos.

> Detalle de implementación que cuesta media hora si no se sabe: los selectores
> por namespace con regex necesitan **frontera final**
> (`/^App\\Modules\\[^\\]+\\Data(\\|$)/`). Sin ella, `Data` también captura
> `Database`, y el seeder del módulo acaba acusado de ser un DTO.

### Llamadas prohibidas — `phpstan-disallowed.neon`

Una entrada por regla, con `message:` citando su número y `allowIn:` /
`allowExceptIn:` diciendo **dónde sí** es correcto:

| Identificador | Regla | Prohíbe | Dónde sí |
|---------------|-------|---------|----------|
| `kore.r19` | R19 | `auth()`, `request()`, `session()`, `cookie()` | todo menos `Core`, `Models`, `Actions`, `Data`, `Rules` |
| `kore.r20` | R20 | `abort()`, `abort_if()`, `abort_unless()` | `app/Http`, `Modules/*/Http`, `routes` |
| `kore.r17` | R17 | `env()` | `config/` |
| `kore.r18` | R18 | `dd()`, `dump()`, `var_dump()`, `ray()` | en ningún sitio |
| `kore.r21` | R21 | `DB::table()` | migraciones y seeders |
| `kore.r22` | R22 | cliente HTTP y `Mail::send` / `Mail::raw` | todo menos controllers y componentes Livewire |
| `kore.r27` | R27 | `Model::unguard()` | en ningún sitio |

> Otro detalle que cuesta caro: la firma que hay que escribir es la que ve
> Larastan **después** de resolver el facade, no el facade. `DB::table()` se
> configura como `Illuminate\Database\ConnectionInterface::table()` y `Http::get()`
> como `Illuminate\Http\Client\PendingRequest::*`; el facade `Mail`, en cambio,
> se detecta por su propio nombre. Comprobado entrada por entrada introduciendo
> una violación temporal y viendo el error.

### Checks textuales — `php artisan kore:arch:check`

Lo que ninguna de las anteriores ve: atributos que faltan, comentarios,
estructura de archivos. Todo por lectura de archivos, sin tocar la base de
datos, en ~0,2 s.

```bash
composer arch                                  # todo el repositorio
php artisan kore:arch:check --files=a.php,b.md # lo que usa el pre-commit
php artisan kore:arch:check --rule=R29         # un solo check
php artisan kore:arch:check --root=/otro/repo  # otra raíz (lo usan sus tests)
```

| Check | Qué mira |
|-------|----------|
| R11 | toda clave de `config/kore-app.php` la lee alguien |
| R23 | todo método de escritura de un componente Livewire llama a `authorize()`, `can()` o `Gate::`. Prefijos reconocidos: `save*`, `store*`, `create*`, `update*`, `delete*`, `destroy*`, `remove*`, `confirm*`, `toggle*`, `add*`, `send*`, `sync*`, `assign*`, `approve*`, `import*` |
| R24 | `#[Locked]` en `public $id` / `$model` / `$algoId` de `Forms/` y `Http/Livewire/` |
| R29 | toda migración define `down()` |
| R30 | ninguna Blade usa Eloquent |
| R37 | ninguna Blade tiene `data-testid` |
| R38 | ningún `.ts` de `tests/e2e` llama a `waitForTimeout()` (los comentarios que explican por qué no se usa, sí) |
| R40 | todo `docs/**/*.md` está enlazado desde `docs/README.md`, y toda `R{n}` citada en el código, los skills, los `*.neon` y `CLAUDE.md`/`AGENTS.md` existe en `rules.md` (cuenta cualquier `R{n}` suelta, no sólo las seguidas de `:` o `·`) |
| R44 | las válvulas de escape tienen la gramática correcta, citan una regla existente, llevan `@owner`, no han caducado y **usan la forma que esa regla declara** en su `> Escape:` |
| R45 | si hay `phpstan-baseline.neon`, su primera línea es `# arch-baseline: vence YYYY-MM-DD` y la fecha no ha pasado |
| R49 | cada `.agents/skills/{nombre}` tiene su symlink en `.claude/skills/{nombre}` apuntando a `../../.agents/skills/{nombre}`, y en `.claude/skills/` no hay nada que no sea uno de esos enlaces |
| R50 | `AGENTS.md` es exactamente lo que generaría `php artisan kore:agents:sync` desde `CLAUDE.md` |
| R52 | toda ruta `GET` con nombre de `routes/web.php` y `app/Modules/*/Routes/web.php` aparece en `tests/e2e/fixtures/access-map.ts`. Lee el texto de los archivos de rutas componiendo los `->prefix()` de los grupos; ignora las rutas con parámetro y las de `/__e2e__`. Si el mapa no existe todavía, avisa una vez y no falla. La válvula es **de línea** |

Salida: `R{n} archivo:línea mensaje`, y exit code 1 si hay algo. Tests:
`tests/Feature/ArchCheckCommandTest.php` (94 casos, un árbol de fixtures que
viola cada check y otro que lo cumple).

### Rector — `rector.php`

Sets aplicados:

- `LevelSetList::UP_TO_PHP_84` — modernización PHP 8.4
- `SetList::CODE_QUALITY` — calidad general
- `SetList::DEAD_CODE` — código muerto
- `SetList::EARLY_RETURN` — early returns
- `SetList::TYPE_DECLARATION` — tipos faltantes
- `SetList::PRIVATIZATION` — `private` donde sea posible
- `LaravelLevelSetList::UP_TO_LARAVEL_130` — patrones Laravel 13
- `LaravelSetList::LARAVEL_CODE_QUALITY`
- `LaravelSetList::LARAVEL_COLLECTION`

Excluye: `database/migrations` y `app/Modules/*/Database/Migrations`.

### Pest 5 — `tests/Pest.php`

Aplica `Tests\TestCase` + `RefreshDatabase` a todos los Feature tests, incluyendo los de cada módulo:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Tests dentro de cada módulo (app/Modules/{X}/Tests/{Feature|Unit})
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Feature');

pest()->extend(TestCase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Unit');
```

`tests/Arch/` no aparece: los arch tests son estáticos, no bootean la aplicación
ni tocan la base de datos, así que no necesitan `TestCase` ni `RefreshDatabase`.

`tests/TestCase.php` ejecuta `withoutVite()` en `setUp()` para que los tests no
requieran assets compilados.

Las suites las declara `phpunit.xml`: `Arch` (`tests/Arch`), `Unit`
(`tests/Unit`), `Feature` (`tests/Feature`) y `Modules` (`app/Modules/*/Tests`).

`tests/Arch/PhpatArchitecture.php` vive dentro de `tests/Arch` pero **no** es un
test de PHPUnit: no acaba en `Test.php`, así que el descubrimiento de suites lo
ignora. Lo consume PHPStan.

### Arch tests de Pest — `tests/Arch/ArchitectureTest.php`

| Regla | Expectativa |
|-------|-------------|
| preset `php()` | buenas prácticas base de PHP |
| preset `security()` | sin `md5`, `sha1`, `eval`, `extract`, `mt_rand`... |
| preset `laravel()` | convenciones del framework, **ignorando `App\Modules`, `App\Core\Enums`, `App\Core\Console` y `App\Core\Http\Api`** |
| R13 | `declare(strict_types=1)` en todo `App` |
| R17 · R18 | sin `dd`, `dump`, `var_dump`, `ray` ni `env()` dentro de `App` |
| R1 · R2 | `App\Modules\*\Actions` son `final`, con sufijo `Action` y extienden `App\Core\Actions\Action` |
| R3 | un módulo sólo tiene las carpetas permitidas (y `Database/`, `Http/` y `Resources/` sólo sus subcarpetas fijas); los `Enums/` son enums backed y los `Http/Resources/` extienden `JsonResource` |
| R5 | `App\Modules\Users` no usa `App\Modules\Auth` (y al revés), ignorando `Tests/` y `Events/`; `PhpatArchitectureTest` comprueba además la forma de la regla que PHPat genera para cada par |
| R5 · R14 | `App\Modules\*\Events` son `final readonly` |
| R6 | `App\Core` no usa `App\Modules` |
| R7 | `App\Core\Contracts` son interfaces |
| R8 | `App\Modules\*\Data` y `App\Core\Data\Authorization` son `final`, extienden `App\Core\Data\Data` y no usan `Illuminate\Http` |
| R9 | `App\Modules\*\Providers` son `final` y con sufijo `ServiceProvider` |
| R14 | `App\Modules\*\Rules` son `final` e implementan `ValidationRule` |
| R25 | `App\Modules\*\Policies` son `final` y con sufijo `Policy` |
| R54 | los `Http/Controllers/Api`, `Http/Resources/Api` y `Http/Requests/Api` de cada módulo extienden `ApiController`, `BaseApiResource` y `BaseApiRequest`; y las cinco piezas del contrato existen |

Los namespaces usan comodín (`App\Modules\*\Actions`), así que un módulo nuevo
queda cubierto sin tocar el archivo.

Excepciones documentadas en el propio archivo:

- El preset `laravel()` se aplica con `->ignoring([...])`. El preset asume el
  layout plano del framework (sólo `App\Http\Controllers` puede tener sufijo
  `Controller`, sólo `App\Models` puede extender `Model`, sólo
  `App\Console\Commands` puede extender `Command`...), que es justo lo contrario
  de un modular monolith. Sigue vigilando `App\Core`, `App\Models` y
  `App\Providers`; las reglas equivalentes para los módulos se escriben a mano
  debajo.
- `App\Core\Enums` y `App\Core\Console` quedan fuera del preset por lo mismo: el
  enum compartido y los comandos transversales viven en Core, no en `App\Enums`
  ni en `App\Console\Commands`. `App\Core\Http\Api` se sumó en la v2.2.0: el
  contrato de la API son un `ApiController` abstracto y un `BaseApiRequest` que
  extiende `FormRequest`, y el preset los quiere en `App\Http\Controllers` y
  `App\Http\Requests`. Que los módulos los hereden es justo lo que verifica R54.
- Los tres checks de **R54** van como `test()` con glob y no como
  `arch()->expect(...)->toExtend(...)`: hoy dos de los tres namespaces están
  vacíos —el boilerplate publica un solo endpoint— y `arch()` sobre un namespace
  sin clases falla por «no existe», que no es lo que la regla dice. El glob es
  tolerante y se tensa solo cuando un módulo crea su primera clase. Misma
  decisión que con `Enums/` y `Http/Resources/` en la v2.1.0.
- Desde la v1.1 **no hay excepciones en la regla de Actions**: los stubs que
  publica Fortify viven en `App\Modules\Auth\Fortify`, porque son adaptadores del
  paquete y no casos de uso del boilerplate.

## Hooks de git

`igorsgm/laravel-git-hooks` los instala automáticamente vía
`post-autoload-dump`:

```php
// config/git-hooks.php
'pre-commit' => [
    PintPreCommitHook::class,            // formato
    ArchCheckPreCommitHook::class,       // kore:arch:check sobre los staged
],

'commit-msg' => [
    ConventionalCommitMsgHook::class,    // el asunto sigue Conventional Commits (R43)
],

'pre-push' => [
    PrePushHook::class,                  // phpstan + pest --parallel
],
```

> **Bug conocido del paquete (2.1) y cómo lo esquiva el boilerplate.** El
> script que `git-hooks:register` escribe en `.git/hooks/pre-push` reenvía con
> `$@` los dos argumentos que git pasa al hook (`remote` y `url`), pero el
> comando `git-hooks:pre-push` del paquete no declara argumentos, así que todo
> `git push` moría con `No arguments expected ... got "origin"` sin llegar a
> correr nada. `App\Core\Console\Hooks\PrePushCommand` registra un comando
> con el mismo nombre y la firma `{remote?} {url?}`; como el provider de la app
> arranca después que el del paquete, reemplaza al original. `GitHooksTest` lo
> verifica. Si el paquete lo corrige aguas arriba, basta con borrar esa clase y
> su registro en `AppServiceProvider`.

> **Segunda trampa del mismo hook.** Pest corre como proceso hijo de un
> `artisan` que ya cargó `.env` en `$_SERVER`, y los `<env>` de `phpunit.xml`
> no pisan variables ya presentes. Sin `force="true"` la suite heredaba
> `APP_ENV=local` y el `.env` del desarrollador (seis tests rojos sólo dentro
> del hook). Por eso todos los `<env>` de `phpunit.xml` llevan `force="true"`
> y el hook añade `APP_ENV=testing` al proceso. Consecuencia: para correr la
> suite contra otra base (MySQL, por ejemplo) hay que editar `phpunit.xml`, no
> exportar variables.


Las tres clases propias viven en `app/Core/Console/Hooks/`:

- **`ArchCheckPreCommitHook`** toma los archivos del commit y se los pasa al
  comando por `--files`. Si el comando devuelve distinto de 0, lanza
  `HookFailException` y el commit se aborta.
- **`PrePushHook`** corre PHPStan y después Pest en paralelo, parando en el
  primero que falle e imprimiendo su salida. Rector, `composer audit` y el build
  de Vite **no** están aquí a propósito: romperían el presupuesto de 30 s.
- **`ConventionalCommitMsgHook`** mira la **primera línea útil** del mensaje —la
  primera que no es una línea en blanco ni un comentario de git— contra
  `tipo(ámbito)!: descripción`. Deja pasar lo que escribe git por su cuenta
  (`Merge …`, `Revert "…"`, `fixup!`, `squash!`, `amend!`) y no toca el cuerpo ni
  el pie del mensaje. Si falla, imprime el formato y un ejemplo válido. Es la
  capa más barata del pipeline: una expresión regular, sin procesos ni base de
  datos.

> **Los hooks no escriben archivos.** Cuando `CLAUDE.md` entra en un commit sin
> su `AGENTS.md` regenerado, el pre-commit falla y dice `php artisan
> kore:agents:sync` — pero no lo corre él. Un hook que modifica el árbol deja
> commiteado algo distinto de lo que el desarrollador revisó, y encima en
> silencio.

Tests: `tests/Feature/GitHooksTest.php` (36 casos) prueba la decisión de cada
hook (seguir o abortar) con un `Command` de doble y `Process::fake()`. No se prueba con un
commit real porque haría falta escribir un archivo con una violación dentro de
`app/` o `tests/`, y con `pest --parallel` ese archivo lo vería el proceso que
corre los arch tests de verdad.

Para reinstalarlos a mano:

```bash
php artisan git-hooks:register
```

> En un **git worktree** `git-hooks:register` falla con «Git not initialized in
> this project»: el paquete busca `.git/hooks` como directorio y en un worktree
> `.git` es un archivo que apunta al repositorio principal. Los hooks se instalan
> desde el clon normal; en un worktree se verifican llamando al comando a mano:
> `php artisan git-hooks:commit-msg <ruta-al-mensaje>`.

## CI — GitHub Actions

`.github/workflows/ci.yml`:

Job `quality`:

- Matrix PHP 8.4 / 8.5 (8.3 se retiró en la v1.4.1: el lock exige 8.4)
- Pasos (en orden, fallan rápido):
  1. `composer install` (cache de `vendor/`)
  2. `composer audit` — advisories de seguridad, bloqueante
  3. `vendor/bin/pint --test --format=checkstyle`
  4. `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` (Larastan + PHPat + disallowed-calls)
  5. `php artisan kore:arch:check`
  6. `vendor/bin/rector process --dry-run --no-progress-bar`
  7. `vendor/bin/pest --parallel --compact`

Job `assets`: `composer install` (el CSS importa el tema de koreUi desde
`vendor/`), Node 20, `npm ci`, `npm run build`. Los tests corren con
`withoutVite()`, así que sin este job un build de Vite roto pasaría verde.

- Trigger: `push` a `main` y todo `pull_request` contra `main`
- Concurrency: cancela ejecuciones previas del mismo branch

`.github/workflows/release.yml`:

Se dispara **sólo al empujar un tag `v*`**, con `permissions: contents: write` y
nada más. Tres pasos:

1. `composer install` (con dev: `PrePushCommand` extiende una clase de
   `igorsgm/laravel-git-hooks`, que es `require-dev`, y `AppServiceProvider` la
   registra; con `--no-dev` cualquier `php artisan` moriría al autocargarla).
2. `php artisan kore:changelog:section "$GITHUB_REF_NAME"` → `release-body.md`.
   Si el tag no tiene su sección en `CHANGELOG.md`, el comando devuelve 1 y el
   job falla: **el release no se publica** (R42).
3. `softprops/action-gh-release@v2` con `name: vX.Y.Z` y `body_path:
   release-body.md`.

**Por qué no release-please.** Generaría el CHANGELOG en inglés desde los
subjects de los commits y chocaría con el que hay: aquí está escrito a mano, en
español y con nota de migración, porque es la API de actualización de los
proyectos derivados (R42). El workflow *lee* ese archivo en vez de reescribirlo.

Además del workflow, el repositorio trae `.github/PULL_REQUEST_TEMPLATE.md`
(checklist de `composer ci`, E2E, doc en el mismo commit, CHANGELOG y válvulas),
`.github/ISSUE_TEMPLATE/` (bug, propuesta y `config.yml` con
`blank_issues_enabled: false`) y `.github/CODEOWNERS`, que pide review explícito
en `docs/architecture/rules.md`, `config/kore-app.php`, `.github/` y `app/Core/`.

## Estado actual

```
$ composer ci
✓ Pint passed
✓ Larastan nivel 8 + PHPat + disallowed-calls: 0 errors
✓ kore:arch:check: sin violaciones
✓ Rector: nothing to refactor
✓ Pest: 570 passed (1461 assertions)
```

Reparto de los 570 tests: 38 arch (`tests/Arch`: 27 en `ArchitectureTest` y 11
en `PhpatArchitectureTest`), 80 del módulo Auth, 48 del módulo Users, 3 de
Tenancy, 43 del módulo Docs, 29 del módulo E2E y 329 en `tests/Feature` (health,
scheduler, Sentry, Pulse, Pennant, mass assignment, landing, traducciones,
backup, cabeceras de seguridad, configuración de producción, logging,
migraciones reversibles, instalación limpia, el MCP `kore`, `kore:arch:check`
con sus 94 casos, los hooks, `kore:agents:sync`, `kore:changelog:section`, el
419, `HasPublicUuid` y los 51 de `tests/Feature/Api` —el contrato de la API:
renderer de errores, middleware, limiters, paginación por cursor, `EnumResource`
y la documentación de Scramble). Aparte, la suite E2E de Playwright (`npm run e2e`): 163
tests en 18 archivos —17 de spec más `auth.setup.ts`, que hace el login por
rol—, 104 de ellos generados desde `tests/e2e/fixtures/access-map.ts`.

Actualiza esta cifra cuando cambie (R41). Un número inventado en los docs es
peor que no ponerlo: la auditoría de septiembre de 2026 encontró aquí «15 tests»
cuando había 32.

## Cómo subir el listón

| Cambio                        | Impacto                                      |
|-------------------------------|----------------------------------------------|
| Larastan nivel 8 → 9          | strict, harder, prepara baseline (con fecha, R45) |
| Pest coverage min 80% → 90%   | `test:coverage` exige más cobertura          |
| Agregar mutation testing      | `composer require --dev pestphp/pest-plugin-mutate` |
| Validar el **cuerpo** del commit-msg | hoy sólo se mira el asunto; el pie (`Refs:`, `BREAKING CHANGE:`) no |

Antes de subir el nivel, agrega un baseline para no romper nada existente:

```bash
./vendor/bin/phpstan analyse --generate-baseline
```

Y ponle la cabecera de caducidad en la primera línea, o `composer arch` lo
rechazará (R45):

```
# arch-baseline: vence 2027-03-01
```
