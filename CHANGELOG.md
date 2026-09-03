# Changelog

Todos los cambios relevantes de este boilerplate se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el versionado sigue [Semantic Versioning](https://semver.org/lang/es/).

Para un proyecto derivado, este archivo es la guía de qué aplicar al actualizar
desde el upstream (`git remote add kore https://github.com/koreui/kore-laravel`).

## [Unreleased]

## [1.1.0] - 2026-09-02

«El boilerplate se demuestra a sí mismo». La v1.0.0 cerró la brecha entre lo que
los docs prometían y lo que el código *hacía*; ésta la cierra en lo que el
código *es*. El módulo Users —el CRUD de referencia— ahora cumple de verdad las
tres reglas de oro que CLAUDE.md predicaba y que él mismo incumplía: Action
Pattern, DTOs y cero imports cruzados entre módulos. Los arch tests que estaban
comentados con un `TODO v1.1` pasan a fallar el build.

Sin cambios visibles para el usuario final: la suite E2E (45 specs) pasa sin
tocar un solo texto.

### Seguridad

- **Escalada de privilegios al asignar roles y permisos (alta).** Cualquiera con
  `users.create` + `users.edit` podía crear una cuenta con **cualquier** rol y
  **cualquier** permiso del sistema —incluidos los que él mismo no tenía— y
  entrar con ella. Dos reglas nuevas en `app/Modules/Users/Rules/` lo cierran:
  `GrantablePermission` (sólo concedes permisos que tienes) y `GrantableRole`
  (sólo asignas un rol si posees todos sus permisos, medido en permisos y no en
  nombres de rol, para que un rol nuevo quede cubierto solo). El superadmin las
  salta; el actor se pasa por constructor, así que dentro de la regla no se lee
  `auth()`. Cubierto por `PrivilegeEscalationTest`.
  - Limitación conocida: la matriz de permisos de la vista sigue mostrando todos
    los permisos aunque el actor no pueda concederlos. La validación los
    rechaza nombrando el permiso; filtrarlos también en el cliente queda
    pendiente.

### Añadido

- **i18n por módulo con español como idioma fuente**: `APP_LOCALE=es`,
  fallback `en`, faker `es_ES`. `lang/es/{auth,pagination,passwords,validation}.php`
  traducidos; `en.json` en `app/Modules/{Modulo}/Resources/lang/` cargado por
  cada provider, `lang/en.json` compartido y `lang/es.json` para literales
  ingleses de Fortify y correos del framework; español para el correo del
  magic link (`lang/vendor/one-time-passwords/es`). `TranslationsTest` falla
  listando cada `__()` sin traducción. `phpunit.xml` fija el locale para que
  la suite no dependa del `.env` local. Guía: `docs/guides/i18n.md`.
- **Action Pattern real en Users**: `UserCreateAction`, `UserUpdateAction` y
  `UserDeleteAction` (`final`, extienden `App\Core\Actions\Action`, un único
  `handle()`), con `UserData` como DTO y los eventos `UserCreated`,
  `UserUpdated` y `UserDeleted` (`final readonly`) como canal para otros
  módulos. Ninguna Action lee `auth()`, `request()` ni `session()`: sirven igual
  desde un job o un comando. Tests: una clase por Action.
- **`App\Core\Enums\SystemRole`** (`Superadmin` / `Admin` / `User`) — el valor de
  los roles pasa a Core, donde cualquier módulo puede mirarlo.
- **`App\Core\Contracts\AuthorizationCatalog`** + DTOs
  `App\Core\Data\Authorization\{RoleOptionData, PermissionOptionData,
  PermissionModuleData}`, implementado por
  `App\Modules\Auth\Support\AuthorizationCatalog` y bindeado en
  `AuthModuleServiceProvider::register()`. Es la frontera que permite a Users
  dejar de importar `Auth\Models\{Role, Module}`.
- **`App\Modules\Auth\Actions\AuthUserRegisterAction`** + `RegisterData`: el
  registro público también es un caso de uso, y el stub de Fortify sólo valida y
  delega.
- **Dashboard como componente Livewire** (`Auth\Http\Livewire\Dashboard` +
  `DashboardStatData`). La blade hacía `User::count()`, `Permission::count()` y
  `Module::where(...)->count()` dentro de un `@php`; ahora la ruta es
  `Route::get('/dashboard', Dashboard::class)` y las cifras llegan como DTOs.
  Mismo HTML, mismos textos.
- **Factories por módulo**: `AppServiceProvider::configureFactories()` mapea
  `App\Modules\{X}\Models\{Y}` → `App\Modules\{X}\Database\Factories\{Y}Factory`,
  y `Role` y `Module` estrenan `HasFactory` + `RoleFactory` / `ModuleFactory`.
  Si un modelo no tiene factory, el resolver dice dónde la buscó en vez del
  "Class not found" de PHP.
- **Arch tests nuevos** (16 en total, antes 9): imports cruzados entre módulos en
  ambos sentidos (ignorando `Tests/`), `App\Core` no depende de `App\Modules`,
  los `Core\Contracts` son interfaces, los DTOs son `final` y extienden
  `Core\Data\Data`, y las Actions extienden `Core\Actions\Action`.
- Tests: 100 → 139 Pest (45 E2E sin cambios).

### Cambiado

- **`UserForm` ya no persiste**: `store()` desaparece y en su lugar hay
  `toData(): UserData`. `FormComponent::save()` hace
  autorizar → validar → DTO → Action → toast → redirect, con las Actions por
  inyección de método. `TableUsers::confirmDelete()` delega en
  `UserDeleteAction`.
  - Ahí sí se resuelve con `resolve(...)` y no por inyección: el diálogo de
    confirmación de koreUi invoca el método con
    `$this->{$method}(...$params)`, sin pasar por el contenedor. Un parámetro
    tipado de más sólo reventaría en el navegador, no en los tests.
- **Los stubs de Fortify se mudan** de `App\Modules\Auth\Actions\Fortify\` a
  `App\Modules\Auth\Fortify\`: son adaptadores del paquete (el nombre y la
  firma los fija Fortify), no casos de uso, y ensuciaban la regla de Actions con
  una excepción permanente. Los nombres de clase no cambian.
- `Role::SUPERADMIN`, `ADMIN` y `USER` **siguen existiendo**, pero ahora se
  definen desde `SystemRole` (`= SystemRole::Admin->value`), igual que
  `allRoles()` y `assignableNames()`.
- `UserPolicy` y `TableUsers` comparan contra `SystemRole::Superadmin->value`;
  las opciones del select de rol y la matriz de permisos salen del
  `AuthorizationCatalog` (serializadas con `->toArray()` a la misma estructura
  de antes, así que las vistas no cambian).
- El preset `laravel()` de los arch tests se aplica ignorando también
  `App\Core\Enums` (el preset exige que sólo `App\Enums` contenga enums, cosa
  que no encaja con el layout modular).
- Documentación al día con el código: `docs/guides/crud.md` reescrita alrededor
  de Form + Data + Actions + Events, `docs/modules/users.md`,
  `docs/modules/auth.md`, `docs/architecture/module-pattern.md`,
  `docs/architecture/authorization.md` y `docs/quality/pipeline.md`. Los skills
  (`kore-action-create`, `module-scaffold`, `kore-livewire-create`) reflejan el
  patrón real, y su copia en `.agents/skills/` es idéntica.

### Nota de migración (proyectos derivados)

1. **Namespace de Fortify**: cambia `App\Modules\Auth\Actions\Fortify\*` por
   `App\Modules\Auth\Fortify\*` (los nombres de clase son los mismos). Si
   personalizaste `FortifyServiceProvider`, revisa sus `use`.
2. **`UserForm::store()` ya no existe.** Si lo llamabas desde tu código, usa
   `UserCreateAction` / `UserUpdateAction` con `UserForm::toData()`. Si añadiste
   campos al formulario, muévelos de `store()` a la Action correspondiente y al
   DTO.
3. **`Role::` sigue igual**: las constantes y `allRoles()` / `assignableNames()`
   no cambian de firma. Si comparas roles desde otro módulo, migra a
   `SystemRole::Superadmin->value` para no romper el arch test de imports
   cruzados.
4. **Formularios que asignan roles o permisos**: las reglas anti-escalada
   aplican al `UserForm`. Si tu app da de alta usuarios desde un actor con
   permisos limitados, revisa que ese actor tenga los permisos que concede (o
   dale el rol superadmin al proceso).
5. **Si tenías una factory de un modelo de módulo en `database/factories/`**,
   muévela a `app/Modules/{X}/Database/Factories/` o el resolver no la
   encontrará.

## [1.0.0] - 2026-09-02

Primera versión etiquetada. Cierra la brecha entre lo que la documentación
prometía y lo que el código hacía: se tapan dos escaladas de privilegios en el
módulo Users, se conecta la observabilidad que estaba instalada pero muerta, y
se borra todo lo que era decorativo (toggles que nadie leía, restos de
scaffold, cifras inventadas en los docs).

### Seguridad

- **Escalada de privilegios en Users (crítica).** `UserForm::$id` era `public`
  sin `#[Locked]` y `FormComponent::save()` no llamaba a `authorize()`. Un
  cliente con `users.create` podía fijar `form.id` por `/livewire/update` y
  sobrescribir email, password, rol y permisos de cualquier usuario. Ahora
  `$id` va con `#[Locked]` y `save()` / `mount()` autorizan contra la
  `UserPolicy`.
- **Borrado sin autorización (crítica).** `TableUsers::confirmDelete()` sólo
  comprobaba que no fueras tú mismo; el bloqueo real estaba en el `hidden()`
  del RowAction, que es cliente. Ahora llama a `authorize()`.
- Se elimina `Model::unguard()` global. `UserForm` resuelve el modelo
  explícitamente en vez de `updateOrCreate(['id' => ...])`.
- `throttle:api` en el grupo `api` + `RateLimiter::for('api')`. Laravel 12 no
  trae ese limiter por defecto y sin él `throttle:api` degradaba a 0 intentos.
- `trustProxies` en `bootstrap/app.php`: detrás del Nginx del contenedor
  `$request->ip()` era siempre la IP interna, así que los limiters por IP, los
  logs y Sentry perdían la IP real.
- Magic link: rate limit de envío (5 / 5 min por email + IP) y respuesta
  genérica para no permitir enumeración de usuarios.
- `PULSE_ENABLED` con default `false` de verdad, y gates `viewPulse` /
  `viewHealth` restringidos al rol superadmin.
- `composer audit` bloqueante en CI.

### Añadido

- **Suite E2E con Playwright** (`tests/e2e/`, `playwright.config.ts`):
  45 specs sobre landing, auth (login, registro, reset, magic link con lectura
  del código real desde el log, rutas protegidas) y Users (listado, alta,
  edición, borrado, permisos por rol). Entorno aislado con `.env.e2e`,
  `database/e2e.sqlite` y `E2eSeeder`; fixtures por rol; page objects;
  workflow `e2e.yml`; doc `docs/quality/e2e.md`; skill `kore-e2e-test`;
  scripts `npm run e2e*` y `composer e2e`.
- **Observabilidad conectada de punta a punta**: `Integration::handles()` de
  Sentry en `withExceptions()` y canal de log `sentry`; rutas `/health` (HTML,
  sesión + gate) y `/health/json` (token `HEALTH_SECRET_TOKEN`); scheduler real
  en `routes/console.php` con `health:check`, heartbeat y prunes de queue /
  sanctum / activitylog.
- `LogsActivity` en `User` y `Role` como ejemplo vivo de spatie/laravel-activitylog.
- Config y migración de Pennant publicadas (`Feature::active()` reventaba sin
  la tabla `features`).
- **Arch tests reales** en `tests/Arch/ArchitectureTest.php`: presets `php()`,
  `security()` y `laravel()` (este último ignorando `App\Modules`, cuyo layout
  modular no encaja con el preset), `strict_types` en todo `App`, prohibición
  de `dd`/`dump`/`var_dump`/`ray`/`env()` fuera de config, y convenciones de
  nombre y `final` para Actions, Policies y Providers de módulo. README y
  CLAUDE.md prometían arch tests desde el día uno y no había ninguno.
- `.github/dependabot.yml` con composer, npm y github-actions semanales,
  agrupando minor + patch en un PR por ecosistema.
- Job `assets` en CI (`npm ci && npm run build`): los tests corren con
  `withoutVite()`, así que un build roto pasaba verde.
- Este `CHANGELOG.md`.
- Script `composer e2e`, que delega en `npm run e2e`.
- Test del toggle `AUTH_2FA_ENABLED` (`TwoFactorToggleTest`), incluyendo el
  arranque completo de la aplicación con el toggle apagado.
- Los tres skills propios (`module-scaffold`, `kore-action-create`,
  `kore-livewire-create`) también en `.agents/skills/`, que es lo que README y
  `docs/ai/working-with-ai.md` afirmaban desde antes.
- `.gitkeep` en `app/Modules/Auth/Data/` y `app/Modules/Tenancy/Database/Migrations/`,
  y guarda `is_dir()` en `TenancyModuleServiceProvider` para que un clon fresco
  no reviente al migrar.

### Cambiado

- **`AUTH_2FA_ENABLED` es un toggle de verdad.** `config/fortify.php` leía
  `env()` directamente; ahora la feature `twoFactorAuthentication` la añade o la
  quita `FortifyServiceProvider::register()` según
  `config('kore-app.auth.two_factor')`. Un config no puede leer otro (se cargan
  en orden alfabético y `fortify` va antes que `kore-app`), y el `register()` de
  los providers corre antes del `boot()` en el que Fortify publica sus rutas.
- `artisan about` deja de mostrar Reverb (cosmético, el paquete no está
  instalado) y muestra 2FA, magic links, social login, Pulse y si Sentry tiene
  DSN.
- Documentación sincronizada con el código: cifras de tests reales, lista real
  de archivos de test, `bootstrap/providers.php` con `UsersModuleServiceProvider`,
  colas por driver `database` en vez de "Redis + Horizon" (Horizon no está
  instalado) y el enlace a koreUi apuntando a su repositorio.
- Enlaces del landing y del layout público: `/docs` daba 404 y el botón de
  GitHub era un placeholder; ahora apuntan al repositorio y a `docs/` en GitHub.
- Dependencias actualizadas (sólo minors y patches): laravel/framework
  12.68.0 → 12.69.1, laravel/fortify 1.36.2 → 1.39.0, laravel/pulse 1.7.3 →
  1.8.1, laravel/pennant 1.23.0 → 1.26.0, laravel/pint 1.29.1 → 1.30.5,
  laravel/boost 2.4.6 → 2.7.0, laravel/socialite 5.27.0 → 5.31.0,
  laravel/sanctum 4.3.1 → 4.3.3, laravel/sail 1.58.0 → 1.67.0, laravel/pail
  1.2.6 → 1.2.7, livewire/livewire 4.4.2 → 4.4.3, larastan/larastan 3.9.6 →
  3.11.0, rector/rector 2.4.2 → 2.6.6, driftingly/rector-laravel 2.3.0 →
  2.6.2, sentry/sentry-laravel 4.25.0 → 4.27.0, spatie/laravel-data 4.22.1 →
  4.23.0, spatie/laravel-health 1.39.2 → 1.40.2,
  spatie/laravel-one-time-passwords 1.1.0 → 1.1.2, spatie/laravel-permission
  7.4.1 → 7.4.2, stancl/tenancy 3.10.0 → 3.10.1, pestphp/pest 3.8.6 → 3.8.7,
  phpunit/phpunit 11.5.50 → 11.5.56, mockery/mockery 1.6.12 → 1.6.15,
  nunomaduro/collision 8.9.4 → 8.9.5.
- `app()` → `resolve()` en tres tests (`ApiRateLimitTest`, `HealthTest`,
  `SentryIntegrationTest`) y `(string) request()->ip()` → `request()->ip()` en
  `MagicLink`: lo piden `AppToResolveRector` y `RemoveConcatAutocastRector`,
  reglas nuevas de rector-laravel 2.6.
- `.codex/config.toml` sin la ruta absoluta de la máquina del autor y con el
  servidor MCP de kore-ui, la misma URL que declara `.mcp.json`.

### Corregido

- Borrar un usuario desde la acción de fila de la tabla no hacía nada: koreUi
  2.2 arma el diálogo de `RowAction::confirm()` en el cliente pero no autoriza
  el método en `$koreConfirmable`, así que el listener lo descartaba.
  `TableUsers::hydrate()` lo registra como workaround hasta que koreUi lo
  resuelva.
- `HEALTH_OAUTH_TOKEN` en `.env.example` no coincidía con el
  `HEALTH_SECRET_TOKEN` que lee `config/health.php`.
- `UserForm` citaba `docs/guides/crud/livewire-form.md`, que no existe.
- `ModulesSeeder` decía que el `Gate::before` del superadmin está en
  `AppServiceProvider`; está en `AuthModuleServiceProvider`.
- El landing anunciaba "32 Tests Pest" hardcodeado.

### Eliminado

- **Toggles fantasma de `config/kore-app.php`**: `reverb`, `octane`, `search`,
  el bloque `observability` y la clave `tenancy.mode`. Ninguno lo leía nadie y
  los paquetes correspondientes no están instalados. Reverb, Octane y Scout
  pasan a ser módulos opcionales que se instalan bajo demanda; el modo
  single-db / multi-db se decide en `config/tenancy.php`; Sentry se activa con
  `SENTRY_LARAVEL_DSN` y Pulse con `PULSE_ENABLED`. Las variables
  correspondientes salen de `.env.example`.
- `tests/Feature/ExampleTest.php` y `tests/Unit/ExampleTest.php` (la única
  aserción útil, que `/` responde 200, vive ahora en
  `tests/Feature/PublicPagesTest.php`).
- `expect()->extend('toBeOne')`, `function something()` y el `withoutVite()`
  duplicado de `tests/Pest.php` (ya está en `tests/TestCase.php`).
- `InlineConstructorDefaultToPropertyRector` de `withRules()` en `rector.php`:
  ya venía en `SetList::CODE_QUALITY` y Rector avisaba del duplicado en cada
  ejecución.
- `resources/views/vendor/one-time-passwords/*` y
  `resources/views/vendor/pulse/dashboard.blade.php`: eran byte a byte
  idénticas a las del paquete, así que sólo aportaban deriva silenciosa cuando
  el paquete cambiara. Republicarlas es un `vendor:publish` cuando de verdad
  haya que personalizarlas.

[Unreleased]: https://github.com/koreui/kore-laravel/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/koreui/kore-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/koreui/kore-laravel/releases/tag/v1.0.0
