# Tests E2E con Playwright

**TL;DR**: `npm run e2e` levanta un Laravel aislado (`.env.e2e` + `database/e2e.sqlite` + `E2eSeeder`) y corre la suite de Playwright de `tests/e2e/` contra un Chromium real. Cuatro cuentas sembradas cubren los cuatro niveles de autorización. Cada módulo nuevo añade sus specs a `tests/e2e/specs/{modulo}/`.

## Por qué Playwright standalone y no el browser testing de Pest

- El proyecto está en **Pest 3**; el browser testing llegó en Pest 4. Migrar el runner entero para tener E2E sería un cambio mucho mayor que el problema que resuelve.
- La suite E2E es **independiente del runner PHP**: se puede copiar tal cual a cualquier proyecto derivado del boilerplate, aunque cambie la versión de Pest o incluso el framework de la API.
- Playwright trae de serie lo que hace útil un E2E cuando falla en CI: **trace viewer**, vídeo, screenshot, reporte HTML y `--ui` para depurar paso a paso.
- Separación de responsabilidades: **Pest** prueba unidades, Actions, políticas y componentes Livewire aislados; **Playwright** prueba que el navegador, Livewire, Alpine y koreUi se entienden entre ellos.

## Requisitos en una máquina limpia

```bash
# Node 20+ y PHP 8.4+
composer install
npm ci
npm run e2e:install     # descarga Chromium (playwright install chromium)
npm run e2e
```

No hay que copiar ningún `.env` ni generar ninguna clave: **`.env.e2e` está commiteado** (su `APP_KEY` es desechable y sólo firma sesiones de test) y `globalSetup` se encarga del resto.

## Cómo se aísla el entorno

| Pieza | Qué hace |
| --- | --- |
| `.env.e2e` | `APP_ENV=e2e`, SQLite propia, `SESSION_DRIVER=database`, `MAIL_MAILER=log`, `BCRYPT_ROUNDS=4`, toggles del boilerplate en sus defaults. Laravel lo carga solo cuando arranca con `APP_ENV=e2e`. |
| `database/e2e.sqlite` | Base dedicada, ignorada por git. `globalSetup` la borra y la recrea en cada corrida. |
| `database/seeders/E2eSeeder.php` | Llama a `ModulesSeeder` y crea las cuatro cuentas de prueba. |
| `tests/e2e/global-setup.ts` | Asegura el manifest de Vite, resetea la base, la migra y la siembra, vacía el log de correo y limpia `tests/e2e/.auth/`. |
| `tests/e2e/auth.setup.ts` | Proyecto `setup`: inicia sesión por la UI real con cada cuenta y guarda su `storageState`. |

### El servidor y `APP_ENV=e2e`

`playwright.config.ts` arranca `php artisan serve --host=127.0.0.1 --port=8010` con `env: { APP_ENV: 'e2e' }`. **`APP_ENV` está en `ServeCommand::$passthroughVariables`**, así que el proceso hijo (el servidor embebido de PHP) también lo recibe y carga `.env.e2e`. No hace falta ningún truco con `php -S`.

Dos detalles del arranque:

- `webServer.url` apunta a **`/up`**, no a la landing: esa ruta no lleva middleware `web`, así que responde sin tocar sesión ni base de datos. Importa porque Playwright arranca el `webServer` **antes** de `globalSetup`, que es justo quien crea la SQLite.
- Se pasa `PHP_CLI_SERVER_WORKERS=4`: el servidor embebido es monoproceso por defecto y serializaría todas las peticiones de todos los workers.

### Cuentas sembradas

Todas con el email verificado y contraseña **`password`**:

| Email | Rol | Permisos | Para probar |
| --- | --- | --- | --- |
| `superadmin@e2e.test` | `superadmin` | bypass total (`Gate::before`) | CRUD completo |
| `editor@e2e.test` | `Usuario` | `users.view`, `users.create`, `users.edit` | permisos parciales |
| `viewer@e2e.test` | `Usuario` | `users.view` | sólo lectura |
| `member@e2e.test` | `Usuario` | ninguno (sólo `dashboard.view` del rol) | acceso denegado |

Los specs **no modifican** estas cuentas. Los datos que un test necesita cambiar los crea el propio test, con email único de dominio **`spec.test`** (`uniqueEmail()`), distinto del `e2e.test` de las sembradas para poder filtrarlas en la tabla.

## Comandos

```bash
npm run e2e             # suite completa (headless)
npm run e2e:ui          # modo UI: time-travel, watch, re-run de un test
npm run e2e:headed      # con navegador visible
npm run e2e:debug       # inspector paso a paso
npm run e2e:report      # abre el último reporte HTML
npm run e2e:install     # descarga Chromium
npm run e2e:install:ci  # Chromium + dependencias del sistema (Linux)

npx playwright test tests/e2e/specs/users            # sólo un módulo
npx playwright test -g 'buscador'                    # por nombre
npx playwright test --repeat-each=2                  # caza de flakiness
E2E_BUILD=1 npm run e2e                              # fuerza `npm run build`
```

## Estructura

```
playwright.config.ts            # proyectos, webServer, reporters
tests/e2e/
├── global-setup.ts             # build + reset de la base + limpieza de .auth
├── auth.setup.ts               # proyecto `setup`: login por la UI de cada rol
├── tsconfig.json               # sólo DX del editor; Vite no lo lee
├── fixtures/index.ts           # `test` extendido: opción `role` + fixtures asX
├── pages/                      # page objects
│   ├── LoginPage.ts  RegisterPage.ts  ForgotPasswordPage.ts  MagicLinkPage.ts
│   └── DashboardPage.ts  UsersIndexPage.ts  UserFormPage.ts  DocsPage.ts
├── support/
│   ├── env.ts                  # lee .env.e2e (baseURL, rutas)
│   ├── users.ts                # cuentas sembradas + rutas de storageState
│   ├── data.ts                 # uniqueEmail() / uniqueName()
│   ├── actions.ts              # createUserViaUi()
│   ├── livewire.ts             # espera de hidratación y de round-trip
│   └── mail-log.ts             # lee el código OTP de storage/logs/laravel.log
└── specs/
    ├── smoke/landing.spec.ts
    ├── auth/{login,register,forgot-password,magic-link,protected-routes}.spec.ts
    ├── users/{index,create,edit,delete,dashboard}.spec.ts
    └── docs/{smoke,navigation,authorization}.spec.ts
```

## Convenciones

### Autenticación por rol

Dos patrones, en este orden de preferencia:

```ts
// 1. Todo el describe con el mismo rol. Es el preferido: el `page` sigue
//    siendo el de Playwright, con trace, vídeo y screenshot automáticos.
test.describe('Users · listado', () => {
    test.use({ role: 'superadmin' });

    test('…', async ({ page }) => { /* ya autenticado */ });
});

// 2. Dos roles en el mismo test.
test('viewer no ve lo que editor sí', async ({ asViewer, asEditor }) => { … });
```

Sin `test.use({ role })` el `page` es un **invitado**, que es lo que quieren landing, login y registro.

**Una sesión por worker de Playwright**, no una global (`storageStateFor(role, worker)`). La cookie de sesión es la misma para todos los tests que compartan archivo de `storageState`, y con `fullyParallel` eso significa peticiones concurrentes sobre la misma sesión de Laravel: el síntoma que lo destapó fue que los toast que viajan por `flash` (`Toast::viaSession()`) se los comía la primera petición que llegara, aunque fuese la de otro spec.

### Page objects

- Uno por pantalla, en `tests/e2e/pages/`. Locators como propiedades `readonly`, acciones como métodos cortos.
- Pueden contener aserciones web-first cuando sirven de punto de sincronización (`UsersIndexPage.focusOnRow()`).
- Nada de lógica de negocio ni de aserciones de negocio: eso vive en el spec.

### Localizadores

Por orden de preferencia: `getByRole` → `getByLabel` → `getByPlaceholder` → `getByText`. Tres trampas reales de este stack:

1. **`getByLabel` también mira `aria-label`.** El ojo de `<x-kore::password>` se llama "Mostrar la contraseña", así que `getByLabel('Contraseña')` devuelve 4 elementos. Usa `{ exact: true }`.
2. **El asterisco de `required` va dentro del `<label>`.** En `/register` la etiqueta es literalmente `Contraseña *`.
3. **Con expresión regular, `getByLabel` NO normaliza los espacios** (compara contra el `textContent` crudo, con sus saltos de línea). Prefiere cadena + `{ exact: true }`.

**No se tocan las vistas Blade para meter `data-testid`.** Si un elemento no se puede localizar de forma accesible, se usa un selector CSS estable, se comenta el porqué en el page object y se anota como candidato a mejora de accesibilidad.

### Estabilidad

- **Prohibido `page.waitForTimeout()`.** Se espera a un cambio observable: un toast, una fila, una URL, un `toHaveCount`.
- Tras un click que dispara un round-trip de Livewire, la aserción del cambio observable es la espera. Cuando el round-trip **no** deja nada visible (un `wire:model.live` cuyo re-render no altera la pantalla), usa `withLivewireRoundTrip()`, que espera a la respuesta HTTP real.
- **Espera a que Livewire hidrate** antes de escribir en un `wire:model`: el HTML del servidor se ve mucho antes de que `@livewireScripts` enganche los eventos, y en ese hueco un `fill()` escribe en el input pero nadie escucha el `input`. Lo hace `waitForLivewireReady()`, ya incorporado en los `goto()` de los page objects con Livewire.
- **Cada test crea sus propios datos** con `uniqueEmail()` / `uniqueName()`. La base sólo se resetea en `globalSetup`, así que ningún test puede depender del orden ni del estado que deje otro.
- **Filtra antes de contar.** La tabla de usuarios pagina de 25 en 25 y ordena por `created_at desc`: los usuarios que otros specs crean en paralelo desplazan a los sembrados. Busca primero (`focusOnRow`, o por el dominio `e2e.test`) y cuenta después.
- **Ojo con los rate limiters.** Fortify permite 5 logins por minuto y por `email|ip`; el broker de reset de contraseña, 1 cada 60 s por email; el OTP borra el código anterior al pedir uno nuevo. Los specs que se autentican o piden un enlace se fabrican su propia cuenta con email único para tener su propio cubo.

### Qué debe cubrir un módulo nuevo

Como mínimo, en `tests/e2e/specs/{modulo}/`:

1. **Un smoke**: la pantalla principal del módulo carga con su heading y su título.
2. **Un happy path**: el caso de uso central de punta a punta (crear, enviar, publicar…), con datos únicos creados por el propio test.
3. **Un spec de autorización por rol**: quién entra (200), quién no (403) y qué acciones se le ocultan en la UI.

Usa el skill `kore-e2e-test` (`.agents/skills/kore-e2e-test/`) para el andamiaje.

## Depurar un fallo

```bash
npm run e2e:ui                                   # el mejor punto de partida
npm run e2e:debug -- -g 'nombre del test'        # inspector paso a paso
npm run e2e:headed -- tests/e2e/specs/users      # ver el navegador
npm run e2e:report                               # reporte HTML del último run
npx playwright show-trace tests/e2e/results/<carpeta-del-test>/trace.zip
```

En local `trace` es `on-first-retry` y `retries` es 0, así que **no se graba trace**. Para forzarlo: `npx playwright test --trace on`. Screenshot y vídeo sí se guardan siempre que un test falle, en `tests/e2e/results/`.

En CI el workflow sube `tests/e2e/report` y `tests/e2e/results` como artefactos cuando el job falla.

## CI

`.github/workflows/e2e.yml`, en `push` a `main` y en todo `pull_request` contra `main`:

1. PHP 8.4 (`sqlite3`, `pdo_sqlite`, `mbstring`, `intl`) + cache de `vendor/`
2. Node 20 + `npm ci` (cache de npm)
3. `npm run build`
4. `npx playwright install --with-deps chromium` (cache de `~/.cache/ms-playwright`)
5. `npm run e2e` con `CI=true` → 2 workers, 1 retry, `forbidOnly`
6. Artefactos (reporte + traces/vídeos) sólo si falla, 7 días de retención

Es un workflow **aparte** de `ci.yml` (Pint · Larastan + PHPat + disallowed-calls · `kore:arch:check` · Rector · Pest): la suite E2E tarda más y necesita navegadores, y así un fallo de E2E no oculta uno de calidad ni al revés.

## Troubleshooting

| Síntoma | Causa y arreglo |
| --- | --- |
| `Unable to locate file in Vite manifest` | Falta `public/build/manifest.json`. `globalSetup` lo compila si no existe; si lo tienes obsoleto: `E2E_BUILD=1 npm run e2e` o `npm run build`. |
| El puerto 8010 está ocupado | En local Playwright **reutiliza** el servidor que encuentre (`reuseExistingServer`). Si el que hay no es el de E2E, mátalo: `lsof -ti :8010 \| xargs kill`. |
| `SQLSTATE... database is locked` o datos raros | Base corrupta: `rm -f database/e2e.sqlite*` y vuelve a correr (`globalSetup` la recrea). |
| `browserType.launch: Executable doesn't exist` | Faltan los navegadores: `npm run e2e:install` (en Linux/CI, `npm run e2e:install:ci`). |
| Todos los tests con rol fallan redirigiendo a `/login` | `storageState` caducado o de otra base. Borra `tests/e2e/.auth/` y vuelve a correr; `globalSetup` ya lo hace en cada corrida. |
| `Too many login attempts` en el proyecto `setup` | Rate limiter de Fortify (5/min por `email\|ip`). Ocurre si corres la suite muchas veces seguidas con muchos workers; espera un minuto o baja `--workers`. |
| Un test pasa suelto y falla en la suite | Casi siempre estado compartido: revisa que use `uniqueEmail()` y que filtre antes de contar filas. Repróducelo con `--repeat-each=2`. |

## Workaround vigente: confirmar una row action

Éste es el fallo que justifica la suite entera (**R36**), y hoy está **resuelto y cubierto**.

El diálogo de confirmación de una **row action** del DataTable se construye en el cliente (`RowAction::buildKoreConfirmPayload`) y al aceptar emite `kore:confirm-callback`, pero `InteractsWithFeedback::handleConfirmCallback()` sólo ejecuta métodos previamente autorizados en `$koreConfirmable` — lista que rellena únicamente `Confirm::send()` en el servidor, camino que las row actions no recorren (las bulk actions sí). Con koreUi 2.2, borrar un usuario desde la fila no hacía **nada**: el listener descartaba la llamada y `TableUsers::confirmDelete()` nunca se invocaba.

El workaround vive en `TableUsers::hydrate()`, que añade `confirmDelete` a `$koreConfirmable` después de restaurar el snapshot y antes de despachar el listener. **Se quita en cuanto koreUi autorice las row actions por su cuenta.**

Lo importante para esta guía es *quién lo vio*: los tests de Livewire pasaban en verde porque invocan el método directamente, sin pasar por el diálogo del navegador. Sólo el E2E lo detectó. Por eso `specs/users/delete.spec.ts` cubre los cuatro casos del flujo —el diálogo se abre, cancelar no borra, **confirmar sí borra la fila** (`toHaveCount(0)` + toast) y la acción se oculta a quien no tiene `users.delete`— y no sólo la mitad que se puede comprobar sin navegador.
