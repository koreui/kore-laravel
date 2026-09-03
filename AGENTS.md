# kore-laravel

Boilerplate Laravel 12 production-ready con Livewire 4, Tailwind v4, koreUi y herramientas AI-friendly.

## Idioma de comunicación

El desarrollador trabaja en español. Comunícate en español.

## Documentación detallada

Este archivo contiene las **reglas vivas y resúmenes**. Para detalles, consulta `docs/`:

- [`docs/architecture/overview.md`](docs/architecture/overview.md) — stack y patrón modular monolith
- [`docs/architecture/module-pattern.md`](docs/architecture/module-pattern.md) — cómo se construye un módulo
- [`docs/architecture/toggles.md`](docs/architecture/toggles.md) — `config/kore-app.php`
- [`docs/architecture/authorization.md`](docs/architecture/authorization.md) — roles, permisos y modules
- [`docs/modules/auth.md`](docs/modules/auth.md) — Fortify + Sanctum + permission + 2FA + OTP + Socialite
- [`docs/modules/tenancy.md`](docs/modules/tenancy.md) — stancl/tenancy con toggle
- [`docs/modules/users.md`](docs/modules/users.md) — Users (primer CRUD del boilerplate)
- [`docs/guides/crud.md`](docs/guides/crud.md) — patrón CRUD del boilerplate
- [`docs/ops/deployment.md`](docs/ops/deployment.md) — Docker en VPS
- [`docs/ops/observability.md`](docs/ops/observability.md) — Sentry · Pulse · Health · ActivityLog
- [`docs/quality/pipeline.md`](docs/quality/pipeline.md) — Pint · Larastan · Rector · Pest · hooks · CI
- [`docs/ai/working-with-ai.md`](docs/ai/working-with-ai.md) — Boost · CLAUDE/AGENTS · skills
- [`docs/README.md`](docs/README.md) — índice maestro

**Antes de codificar en un área específica**, lee el doc correspondiente.

## Stack

- PHP 8.3+ (soporta 8.4) · Laravel 12 · Livewire 4 · Alpine.js · Tailwind CSS v4
- Componentes UI: **koreUi** (`<x-kore::*>`), nunca Flux UI ni otras
- Auth: Fortify + Sanctum (toggle) + spatie/laravel-permission
- DTOs: spatie/laravel-data
- Feature flags: Laravel Pennant
- Tests: Pest 3 (con arch tests en `tests/Arch/ArchitectureTest.php`)
- E2E: Playwright standalone (TypeScript) en `tests/e2e/`, entorno aislado con `.env.e2e`
- Calidad: Pint + Larastan nivel 8 + Rector
- Observabilidad: Sentry · Laravel Pulse · spatie/laravel-health · spatie/laravel-activitylog
- AI: Laravel Boost MCP + skills propios en `.claude/skills/` (module-scaffold, kore-action-create, kore-livewire-create, kore-e2e-test)

## Arquitectura — Modular Monolith + Action Pattern

```
app/
├── Core/                       # kernel compartido (no negocio)
│   ├── Actions/Action.php      # base abstracta
│   ├── Concerns/               # traits compartidos
│   ├── Contracts/              # interfaces compartidas (fronteras entre módulos)
│   ├── Data/Data.php           # base DTO (extiende spatie/laravel-data)
│   ├── Enums/                  # valores compartidos (SystemRole)
│   └── Support/                # helpers
├── Modules/{Domain}/
│   ├── Actions/                # 1 clase = 1 caso de uso, método handle()
│   ├── Data/                   # DTOs del módulo
│   ├── Events/                 # lo que otros módulos pueden escuchar
│   ├── Forms/                  # Livewire Form Objects (rules() + toData())
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Livewire/
│   │   └── Requests/
│   ├── Models/
│   ├── Policies/
│   ├── Rules/                  # reglas de validación propias
│   ├── Support/                # implementaciones de contratos de Core
│   ├── Routes/                 # web.php, api.php cargados por su provider
│   ├── Database/
│   │   ├── Migrations/
│   │   ├── Factories/          # {X}Factory de los modelos del módulo
│   │   └── Seeders/
│   └── Providers/{Module}ServiceProvider.php
├── Models/User.php             # único modelo verdaderamente global
└── Providers/
```

### Reglas de oro

1. **1 Action = 1 caso de uso** con un único método público `handle(...)`,
   `final`, extendiendo `App\Core\Actions\Action`. Naming:
   `{Domain}{Object}{Verb}Action` (`AuthUserRegisterAction`, `OrderCancelAction`);
   cuando el objeto coincide con el dominio se omite el prefijo repetido
   (`UserCreateAction` en el módulo Users). Dentro de una Action **no** se lee
   `auth()`, `request()` ni `session()`: autoriza quien llama, para que la
   Action sirva igual desde un job o un comando.
2. **Sin lógica de negocio en controllers, componentes Livewire ni Form
   Objects**: el Form valida y empaqueta (`rules()` + `toData()`), el componente
   hace `autorizar → validar → DTO → Action`, y la escritura vive en la Action.
3. **Sin imports cruzados entre módulos** (hay arch test, en ambos sentidos).
   Comunicación por Events (`{Domain}\Events\`), Contracts en `Core/Contracts/`
   implementados en `{Domain}\Support\`, enums compartidos en `Core/Enums/`, o
   llamando Actions públicas del módulo destino vía interfaz. `App\Core` nunca
   depende de `App\Modules`.
4. **DTOs en lugar de arrays asociativos** entre capas. Usar `spatie/laravel-data` extendiendo `App\Core\Data\Data`.
5. **`declare(strict_types=1)`** en TODOS los archivos PHP creados.
6. **`final class`** por defecto, salvo que se necesite herencia explícita.
7. **Type hints obligatorios** en todos los parámetros, return types y propiedades.
8. **`CarbonImmutable`** por defecto para fechas.
9. **Tests obligatorios** con Pest para cada Action / endpoint Livewire / ruta.
10. **Factories dentro del módulo**: `App\Modules\{X}\Models\{Y}` resuelve a
    `App\Modules\{X}\Database\Factories\{Y}Factory` (lo registra
    `AppServiceProvider::configureFactories()`).

## Toggles del boilerplate

Configurados en `config/kore-app.php`, todos manejados por `.env`:

| Variable                | Default          | Qué activa                          |
| ----------------------- | ---------------- | ----------------------------------- |
| `API_ENABLED`           | `true`           | Sanctum + rutas API                 |
| `TENANCY_ENABLED`       | `false`          | Módulo Tenancy (stancl/tenancy)     |
| `AUTH_2FA_ENABLED`      | `true`           | 2FA vía Fortify                     |
| `AUTH_MAGIC_LINKS`      | `true`           | spatie/laravel-one-time-passwords   |
| `AUTH_SOCIAL_LOGIN`     | `false`          | Socialite                           |
| `SOCIAL_GOOGLE/GITHUB`  | `false`          | Proveedor específico                |

Esas siete claves son **todas** las de `config/kore-app.php`. Regla: un toggle
sólo existe si alguien lo lee. Reverb, Octane y Scout no son toggles sino
módulos opcionales que se instalan bajo demanda; el modo `single-db`/`multi-db`
de tenancy se elige en `config/tenancy.php` al correr `kore:tenancy:enable`.
Sentry se activa con `SENTRY_LARAVEL_DSN` y Pulse con `PULSE_ENABLED`
(`config/pulse.php`), fuera de `kore-app`.

Cuando un toggle está OFF, su `ServiceProvider` debe hacer `return` temprano y no registrar nada (ni rutas, ni middleware, ni vistas).

⚠️ Un `config/*.php` no puede leer otro: se cargan en orden alfabético. Si un
paquete necesita reaccionar a `kore-app`, múta su config desde el `register()`
del provider del módulo (ver `FortifyServiceProvider::configureTwoFactorFeature()`).

## Componentes UI

- **Siempre** usar componentes de koreUi: `<x-kore::button />`, `<x-kore::input />`, etc.
- Para conocer la API de un componente, llamar a la herramienta MCP de kore-ui (`mcp__kore-ui__get-component-docs`).
- Antes de crear un componente nuevo, verificar con `mcp__kore-ui__list-components` si ya existe.
- **Verificar las props antes de escribirlas.** Una prop que no existe no falla: Blade la vuelca en el
  bag como atributo HTML suelto y el componente usa su valor por defecto en silencio. Así estuvieron
  las alertas de auth pintadas de azul con `color="destructive"` (la prop es `type`).
- `config/kore-ui.php` está **publicado**, y Pint lo reformatea (añade `declare(strict_types=1)` y
  desalinea los `=>`). Al actualizar koreUi hay que reconciliar las claves nuevas a mano con:
  `diff -w vendor/kore-ui/kore-ui/config/kore-ui.php config/kore-ui.php` — el `-w` deja fuera el
  ruido de alineación y solo quedan las diferencias reales.

## Tests E2E (Playwright)

- La suite vive en `tests/e2e/` y se corre con `npm run e2e` (o `composer e2e`). Ver [`docs/quality/e2e.md`](docs/quality/e2e.md).
- Entorno aislado: `.env.e2e` (commiteado, `APP_ENV=e2e`), `database/e2e.sqlite` y `database/seeders/E2eSeeder.php`.
  `globalSetup` construye Vite, recrea la base y siembra; no hace falta preparar nada a mano.
- Cuentas sembradas (password `password`): `superadmin@`, `editor@`, `viewer@`, `member@` + `e2e.test`.
- **Nunca añadas `data-testid` a las Blade para que pase un test.** Localizadores accesibles
  (`getByRole` → `getByLabel` → `getByPlaceholder` → `getByText`); si algo no se puede localizar,
  usa CSS estable y anótalo como mejora de accesibilidad.
- **Prohibido `page.waitForTimeout()`**: espera a un cambio observable (toast, fila, URL, `toHaveCount`).
- Cada test crea sus propios datos con `uniqueEmail()` / `uniqueName()`. La base sólo se resetea en `globalSetup`.
- Todo módulo nuevo con UI aporta como mínimo: un smoke, un happy path y un spec de autorización por rol.
- Skill: `.claude/skills/kore-e2e-test/`.

## Comandos útiles

```bash
# Levantar dev (servidor + queue + logs + vite)
composer dev

# Tests
composer test                       # Pest 3
./vendor/bin/pest --parallel        # paralelo
./vendor/bin/pest --filter=TestName # filtrado
./vendor/bin/pest tests/Arch        # sólo arch tests

composer e2e                        # suite E2E (ver docs/quality/e2e.md)

# Calidad
composer lint                       # Pint
composer analyse                    # PHPStan/Larastan
composer refactor                   # Rector
composer ci                         # todo lo anterior

# Boost MCP (debugging IA)
php artisan boost:mcp               # arranca el server (lo usa la IA)
```

## NO HACER

- ❌ No usar Flux UI ni componentes de otras librerías. Solo koreUi.
- ❌ No poner lógica gorda en controllers, componentes Livewire ni Form Objects (mover a Action).
- ❌ No usar Eloquent en blade directamente. Pasar DTOs / arrays preparados desde el componente Livewire.
- ❌ No tocar `app/Modules/Tenancy/` si `TENANCY_ENABLED=false`.
- ❌ No crear `app/Services/`, `app/Repositories/` globales — todo va dentro del módulo correspondiente.
- ❌ No instalar paquetes nuevos sin consultar al usuario.
- ❌ No bypassear los toggles (`config('kore-app.*')`) con código directo — el boilerplate debe seguir siendo reusable.
- ❌ No meter `data-testid` en las Blade ni usar `waitForTimeout` en los E2E.

## Antes de finalizar cualquier cambio

1. `vendor/bin/pint --dirty --format agent`
2. `./vendor/bin/pest` (al menos los tests del módulo tocado)
3. (Cuando aplique) `./vendor/bin/phpstan analyse`
4. (Si tocaste rutas, vistas, Livewire o permisos) `npm run e2e`; si añadiste una pantalla,
   su spec en `tests/e2e/specs/{modulo}/`

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v12
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
