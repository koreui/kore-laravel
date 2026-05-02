# Pipeline de calidad

**TL;DR**: Pint formatea, Larastan nivel 8 analiza, Rector refactoriza, Pest 3 testea, igorsgm/laravel-git-hooks corre Pint en pre-commit, GitHub Actions corre todo en cada PR. El comando único es `composer ci`.

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

Aplica `Tests\TestCase` + `RefreshDatabase` a todos los Feature tests, incluyendo módulos:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/../app/Modules/*/Tests/Feature');
```

`tests/TestCase.php` ejecuta `withoutVite()` en setUp para que los tests no requieran assets compilados.

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

- Matrix PHP 8.3 / 8.4
- Jobs (en orden, fallan rápido):
  1. `composer install` (cache de `vendor/`)
  2. `vendor/bin/pint --test --format=checkstyle`
  3. `vendor/bin/phpstan analyse --no-progress --memory-limit=2G`
  4. `vendor/bin/rector process --dry-run --no-progress-bar`
  5. `vendor/bin/pest --parallel --compact`
- Trigger: `push` a `main` y todo `pull_request` contra `main`
- Concurrency: cancela ejecuciones previas del mismo branch

## Estado actual

```
$ composer ci
✓ Pint passed
✓ Larastan nivel 8: 0 errors
✓ Rector: nothing to refactor
✓ Pest: 15 passed (28 assertions)
```

## Cómo subir el listón

| Cambio                       | Impacto                                      |
|-------------------------------|----------------------------------------------|
| Larastan nivel 8 → 9          | strict, harder, prepara baseline             |
| Pest coverage min 80% → 90%   | test:coverage exige más cobertura            |
| Agregar mutation testing      | `composer require --dev pestphp/pest-plugin-mutate` |
| Agregar arch tests            | `pest()->test('arch')->expect(...)->in(...)` para reglas de capas |

Antes de subir el nivel, agrega un baseline para no romper nada existente:

```bash
./vendor/bin/phpstan analyse --generate-baseline
```
