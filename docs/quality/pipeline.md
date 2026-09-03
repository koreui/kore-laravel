# Pipeline de calidad

**TL;DR**: Pint formatea, Larastan nivel 8 analiza, Rector refactoriza, Pest 3 testea, igorsgm/laravel-git-hooks corre Pint en pre-commit, GitHub Actions corre todo en cada PR. El comando único es `composer ci`.

Los tests end-to-end en navegador van aparte, con Playwright y su propio workflow: ver [`e2e.md`](e2e.md).

## Comandos

```bash
composer test              # Pest
composer test:parallel     # Pest --parallel
composer test:coverage     # Pest --coverage --min=80
composer lint              # Pint (aplica fixes)
composer lint:test         # Pint --test (no fix)
composer analyse           # Larastan
composer refactor          # Rector (aplica)
composer refactor:test     # Rector --dry-run
composer ci                # lint:test + analyse + refactor:test + test
```

## Configuraciones

### Pint — `pint.json`

Preset Laravel + reglas extra:

- `declare_strict_types`: header obligatorio en cada PHP
- `date_time_immutable`: prefiere `DateTimeImmutable` sobre `DateTime`
- `fully_qualified_strict_types`: imports completos en docblocks
- `modernize_types_casting`: `(int)` en lugar de `intval()`
- `ordered_imports` (alfa)
- `single_quote`: comillas simples por default
- `ordered_class_elements`: orden estable de miembros de clase

### Larastan / PHPStan — `phpstan.neon`

```yaml
level: 8
checkOctaneCompatibility: true
checkModelProperties: true
treatPhpDocTypesAsCertain: false
excludePaths:
  analyseAndScan:
    - app/Modules/*/Database/Migrations/*
    - app/Modules/*/Tests/**/*
```

Pest tests son excluidos porque su sintaxis funcional confunde a PHPStan (no extiende clase, las assertions vienen via `pest()->extend()` en runtime).

### Rector — `rector.php`

Sets aplicados:

- `LevelSetList::UP_TO_PHP_83` — modernización PHP 8.3
- `SetList::CODE_QUALITY` — calidad general
- `SetList::DEAD_CODE` — código muerto
- `SetList::EARLY_RETURN` — early returns
- `SetList::TYPE_DECLARATION` — tipos faltantes
- `SetList::PRIVATIZATION` — `private` donde sea posible
- `LaravelLevelSetList::UP_TO_LARAVEL_120` — patrones Laravel 12
- `LaravelSetList::LARAVEL_CODE_QUALITY`
- `LaravelSetList::LARAVEL_COLLECTION`

Excluye: `database/migrations` y `app/Modules/*/Database/Migrations`.

### Pest 3 — `tests/Pest.php`

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
requieran assets compilados. Está **sólo** ahí: el `beforeEach` que lo repetía en
`tests/Pest.php` se quitó en la v1.0.0.

Las suites las declara `phpunit.xml`: `Arch` (`tests/Arch`), `Unit`
(`tests/Unit`), `Feature` (`tests/Feature`) y `Modules` (`app/Modules/*/Tests`).

### Arch tests — `tests/Arch/ArchitectureTest.php`

Las reglas de oro de `CLAUDE.md` dejaron de ser prosa y fallan el build:

| Regla | Expectativa |
|-------|-------------|
| preset `php()` | buenas prácticas base de PHP |
| preset `security()` | sin `md5`, `sha1`, `eval`, `extract`, `mt_rand`... |
| preset `laravel()` | convenciones del framework, **ignorando `App\Modules`** |
| Regla 5 | `declare(strict_types=1)` en todo `App` |
| — | sin `dd`, `dump`, `var_dump`, `ray` ni `env()` dentro de `App` |
| Regla 1 | `App\Modules\*\Actions` son `final`, con sufijo `Action` y extienden `App\Core\Actions\Action` |
| Regla 3 | `App\Modules\Users` no usa `App\Modules\Auth` (y al revés), ignorando `Tests/` |
| Regla 4 | `App\Modules\*\Data` y `App\Core\Data\Authorization` son `final` y extienden `App\Core\Data\Data` |
| Regla 6 | `App\Modules\*\Policies` son `final` y con sufijo `Policy` |
| — | `App\Core` no usa `App\Modules` |
| — | `App\Core\Contracts` son interfaces |
| — | `App\Modules\*\Providers` son `final` y con sufijo `ServiceProvider` |

Los namespaces usan comodín (`App\Modules\*\Actions`), así que un módulo nuevo
queda cubierto sin tocar el archivo.

Excepciones documentadas en el propio archivo:

- El preset `laravel()` se aplica con `->ignoring('App\Modules')`. El preset
  asume el layout plano del framework (sólo `App\Http\Controllers` puede tener
  sufijo `Controller`, sólo `App\Models` puede extender `Model`...), que es
  justo lo contrario de un modular monolith. Sigue vigilando `App\Core`,
  `App\Models` y `App\Providers`; las reglas equivalentes para los módulos se
  escriben a mano debajo.
- `App\Core\Enums` también queda fuera del preset: éste exige que sólo
  `App\Enums` contenga enums, y en el layout modular el enum compartido vive en
  Core.
- Desde la v1.1 **no hay excepciones en la regla de Actions**: los stubs que
  publica Fortify (`CreateNewUser`, `PasswordValidationRules`...) viven en
  `App\Modules\Auth\Fortify`, porque son adaptadores del paquete y no casos de
  uso del boilerplate.

La regla "sin imports cruzados entre módulos" está **activa desde la v1.1**, en
los dos sentidos y con `Tests/` ignorado (los tests sí pueden cruzar módulos).
La acompañan: `App\Core` no depende de `App\Modules`, los `App\Core\Contracts`
son interfaces, y los DTOs (`App\Modules\*\Data` y `App\Core\Data\Authorization`)
son `final` y extienden `App\Core\Data\Data`.

## Pre-commit hooks

`igorsgm/laravel-git-hooks` instala el hook automáticamente vía `post-autoload-dump`:

```php
// config/git-hooks.php
'pre-commit' => [
    Igorsgm\GitHooks\Console\Commands\Hooks\PintPreCommitHook::class,
],
```

Cada `git commit` corre Pint sobre los archivos staged. Si falla, aborta el commit.

Para ejecutar manualmente:

```bash
php artisan git-hooks:register   # re-registra el hook si fue borrado
```

## CI — GitHub Actions

`.github/workflows/ci.yml`:

Job `quality`:

- Matrix PHP 8.3 / 8.4
- Pasos (en orden, fallan rápido):
  1. `composer install` (cache de `vendor/`)
  2. `composer audit` — advisories de seguridad, bloqueante
  3. `vendor/bin/pint --test --format=checkstyle`
  4. `vendor/bin/phpstan analyse --no-progress --memory-limit=2G`
  5. `vendor/bin/rector process --dry-run --no-progress-bar`
  6. `vendor/bin/pest --parallel --compact`

Job `assets`: Node 20, `npm ci`, `npm run build`. Los tests corren con
`withoutVite()`, así que sin este job un build de Vite roto pasaría verde.

- Trigger: `push` a `main` y todo `pull_request` contra `main`
- Concurrency: cancela ejecuciones previas del mismo branch

## Estado actual

```
$ composer ci
✓ Pint passed
✓ Larastan nivel 8: 0 errors
✓ Rector: nothing to refactor
✓ Pest: 139 passed (346 assertions)
```

Reparto de los 139 tests: 16 arch (`tests/Arch`), 41 del módulo Auth, 48 del
módulo Users, 3 de Tenancy y 31 en `tests/Feature` (health, scheduler, Sentry,
Pulse, Pennant, mass assignment, landing). Aparte, 45 specs E2E de Playwright
(`npm run e2e`).

Actualiza esta cifra cuando cambie. Un número inventado en los docs es peor que
no ponerlo: la auditoría de septiembre de 2026 encontró aquí «15 tests» cuando
había 32.

## Cómo subir el listón

| Cambio                       | Impacto                                      |
|-------------------------------|----------------------------------------------|
| Larastan nivel 8 → 9          | strict, harder, prepara baseline             |
| Pest coverage min 80% → 90%   | test:coverage exige más cobertura            |
| Agregar mutation testing      | `composer require --dev pestphp/pest-plugin-mutate` |
| Arch test de "una Action = un método público" | hoy sólo se vigila el nombre, el `final` y la clase base |

Antes de subir el nivel, agrega un baseline para no romper nada existente:

```bash
./vendor/bin/phpstan analyse --generate-baseline
```
