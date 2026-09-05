# Tests E2E con Playwright

**TL;DR**: `npm run e2e` levanta un Laravel aislado (`.env.e2e` +
`database/e2e.sqlite` + `E2eSeeder`) y corre la suite de Playwright de
`tests/e2e/` contra un Chromium real. Cinco perfiles —el invitado más las
cuatro cuentas sembradas— cubren los cinco niveles de autorización. **Un
guardia de errores monta en todos los tests: si la pantalla lanza una
excepción de JavaScript o el servidor devuelve 500, el test falla aunque su
aserción haya pasado.** Cada pantalla nueva se declara en
`tests/e2e/fixtures/access-map.ts`, y con eso queda cubierta por RBAC y por el
smoke sin escribir un test. Hoy: 176 tests en 19 archivos, 104 de ellos
generados desde el mapa.

## Por qué Playwright standalone y no el browser testing de Pest

- Cuando se creó la suite (v1.0) el proyecto estaba en **Pest 3** y el browser testing llegó en Pest 4. Hoy el proyecto está en Pest 5, pero la suite se mantiene en Playwright a propósito por los dos motivos siguientes.
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
| `.env.e2e` | `APP_ENV=e2e`, SQLite propia, `SESSION_DRIVER=database`, `MAIL_MAILER=log` + `MAIL_LOG_CHANNEL=e2e_mail`, `BCRYPT_ROUNDS=4`, toggles del boilerplate en sus defaults. Laravel lo carga solo cuando arranca con `APP_ENV=e2e`. |
| `database/e2e.sqlite` | Base dedicada, ignorada por git. `globalSetup` la borra y la recrea en cada corrida. |
| `scripts/e2e-seed.sh` | Recrea y siembra `database/e2e.sqlite` a mano, con el mismo candado que `HarnessGuard` escrito en bash. `globalSetup` hace lo mismo desde Node. |
| `database/seeders/E2eSeeder.php` | Llama a `ModulesSeeder` y crea las cuatro cuentas de prueba. |
| `tests/e2e/global-setup.ts` | Asegura el manifest de Vite, resetea la base, la migra y la siembra, vacía el log de correo y limpia `tests/e2e/.auth/`. |
| `tests/e2e/auth.setup.ts` | Proyecto `setup`: inicia sesión por la UI real con cada cuenta y guarda su `storageState`. |

### El servidor y `APP_ENV=e2e`

`playwright.config.ts` arranca `php artisan serve --host=localhost --port=8010` con `env: { APP_ENV: 'e2e' }`. **`APP_ENV` está en `ServeCommand::$passthroughVariables`**, así que el proceso hijo (el servidor embebido de PHP) también lo recibe y carga `.env.e2e`. No hace falta ningún truco con `php -S`.

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

### El harness `/__e2e__/*`

Lo que un spec necesita **de atrezzo** no tiene por qué construirse por pantalla.
Con `E2E_HARNESS=true` (sólo en `.env.e2e`) el módulo E2E publica un puñado de
rutas de servicio para crear usuarios con su rol y sus permisos, entrar como
cualquiera de ellos, leer el último correo y limpiar los limitadores. Lo que el
test **prueba** sigue haciéndose por el navegador. Es un backdoor, y por eso
tiene tres candados —flag, entorno y base de pruebas— que se explican en
[`../modules/e2e.md`](../modules/e2e.md). El cliente TypeScript se describe en
«El harness», más abajo.

## El mapa de acceso: R36 sin escribir tests

[`tests/e2e/fixtures/access-map.ts`](../../tests/e2e/fixtures/access-map.ts) es
la **fuente única** de dos specs que nadie escribe a mano:

- `specs/access/rbac.spec.ts` — cada ruta × cada perfil (**19 × 5 = 95
  comprobaciones**): el status, o la redirección, que el servidor tiene que
  devolver.
- `specs/access/smoke.spec.ts` — cada pantalla que da 200 para alguien (**40**):
  que carga, que muestra su heading, que Livewire hidrata y que no revienta.

Añadir una pantalla al mapa la cubre en los dos. Es la forma automática de
cumplir **R36** («todo módulo con UI aporta smoke + autorización»): la entrada
del mapa *es* el test.

### La forma de una entrada es contrato

```ts
{
    path: '/users/create',
    nombre: 'Users · alta',
    heading: 'Nuevo usuario',
    roles: {
        invitado: 'login',
        member: 403,
        viewer: 403,
        editor: 200,
        superadmin: 200,
    },
},
```

`path` es siempre un **literal entre comillas simples**, absoluto y sin
parámetros. No es capricho: `kore:arch:check` compara las rutas GET con nombre
de los `Routes/web.php` contra estos `path:`, y lee el archivo como texto. Un
`path` construido (`` `/users/${id}/edit` ``) sería invisible para el check.

`heading` es el `getByRole('heading')` que prueba que cargó la pantalla correcta
y no una página de error con buena cara. Se compara por subcadena, así que basta
el trozo estable del título — `'Reglas de arquitectura'` sirve aunque el `<h1>`
real lleve el rango de reglas entre paréntesis.

### Los seis resultados posibles

| Valor | Qué significa | Quién lo provoca |
| --- | --- | --- |
| `200` | Entra | — |
| `403` | Entra la petición, la autorización dice que no | `permission:` o un `Gate` |
| `404` | Ni siquiera existe | un toggle apagado |
| `'login'` | 302 a `/login` | middleware `auth` |
| `'dashboard'` | 302 a `/dashboard` | middleware `guest` sobre alguien que ya entró |
| `'confirm'` | 302 a `/user/confirm-password` | middleware `password.confirm` |

`'dashboard'` y `'confirm'` no estaban previstos y hubo que modelarlos: sin
ellos, `/login` para un usuario autenticado y `/user/passkeys` para cualquiera
no se pueden describir sin mentir.

### Por qué el RBAC usa `page.request` y no `page.goto`

Lo que se comprueba es lo que **devuelve el servidor**, no lo que pinta el
navegador — de eso se ocupa el smoke. Con `page.request.get(path, {
maxRedirects: 0 })`:

- Se reutilizan las cookies del contexto, así que el perfil es el correcto.
- Se ve la redirección **cruda**, con su `Location`. Un `goto` la seguiría y el
  302 a `/login` acabaría siendo un 200 de la pantalla de login: la misma
  respuesta que un 200 legítimo.
- No se carga HTML ni Livewire, así que 70 comprobaciones cuestan lo que una
  pantalla.

### Lo que queda fuera del mapa, y por qué

- **`/users/{user}/edit`** — lleva parámetro, y el mapa es de literales. Su
  autorización la cubre `specs/users/edit.spec.ts`, que crea su propio usuario y
  entra por la fila de la tabla, que es como se llega en la aplicación real.
- **`/up`**, **`/health/json`** — no son pantallas, son endpoints de
  monitorización. `/up` lo cubre `specs/smoke/landing`.
- **Los endpoints de ceremonia** de Fortify y de passkeys — pasos internos de un
  flujo, no pantallas por las que alguien navegue. Los cubren los specs de
  `auth/`.

### El detector de traducciones sin resolver

El smoke comprueba además que ninguna pantalla muestre una clave sin traducir
(la forma `paquete::archivo.clave`). Mira **la prosa, no el código**: se
recortan `pre`, `code`, `script` y `style` de una copia del DOM antes de leer el
texto, porque el visor de `/docs` renderiza PHP de verdad y el detector cazaba
23 llamadas estáticas como falsos positivos (KORE-E2E-001).

## El harness (`/__e2e__/*`): el atrezzo, no lo que se prueba

`tests/e2e/fixtures/harness.ts` es el cliente TypeScript de un backdoor
deliberado que sólo existe en pruebas (`app/Modules/E2E`). Sirve para montar el
escenario sin recorrerlo por pantalla.

**La regla de uso**: lo que el test *prueba* se hace por la interfaz; lo que el
test *da por hecho* se monta con el harness. Un spec del formulario de edición
no debería crear el usuario por la pantalla de alta —ya está probada en otro
sitio— para llegar al campo que le interesa.

```ts
test('el editor no puede borrar', async ({ harness, page }) => {
    await harness.createUser({ role: 'Usuario', email, permissions: ['users.view'] });
    await harness.loginAs(email);

    await page.goto('/users');
    …
});
```

| Método | Endpoint | Para qué |
| --- | --- | --- |
| `ping()` | `GET /__e2e__/ping` | `{ok, app, environment, database, users}` — confirma contra qué base corre la suite |
| `estaDisponible()` | ídem | `false` si responde 404: el módulo no está |
| `loginAs(email)` | `POST /__e2e__/login-as` | Sesión sin formulario |
| `logout()` | `POST /__e2e__/logout` | |
| `createUser({role, email?, name?, password?, permissions?})` | `POST /__e2e__/users` | 201 `{id, email, roles, permissions}` |
| `deleteUser(email)` | `DELETE /__e2e__/users` | Limpieza |
| `lastMail(to?)` | `GET /__e2e__/mail/last?to=` | `{to, subject, body, otp?}` |
| `esperarCorreo(to, timeout)` | ídem, con reintento | Espera a que llegue |
| `clearMail()` | `DELETE /__e2e__/mail` | |
| `notify({email, title?, body?, category?, url?})` | `POST /__e2e__/notify` | Siembra una notificación por `App\Core\Contracts\Notifier` |
| `clearThrottle(keys?)` | `POST /__e2e__/throttle/clear` | Olvida los intentos del limitador |
| `artisan(command, args?)` | `POST /__e2e__/artisan` | Lista blanca: `kore:regenerate-permissions`, `cache:clear` |

Tres cosas que conviene saber:

- **`loginAs()` borra las cookies antes.** Los specs que usan `storageState`
  comparten la misma cookie de sesión dentro de un worker; un `loginAs` sin
  limpiar cambiaría de usuario esa sesión compartida y con ella la de cualquier
  test en curso. Fallos difusos, en otro archivo, sin relación aparente.
- **Un fallo del harness se distingue en el mensaje** (`[harness] Falló el
  montaje al …`). Es un fallo del *montaje*, no de lo que el test probaba, y
  saberlo ahorra media hora buscando el bug en el sitio equivocado.
- **`esperarCorreo()` usa `expect.poll`**, no `setTimeout`. Espera a un evento
  externo con plazo —cada intento es una petición real y en cuanto hay respuesta
  se corta—, que no es el `sleep` ciego que R38 prohíbe.

### Se salta solo mientras el módulo no exista

`specs/harness/harness.spec.ts` hace `test.skip` si `/__e2e__/ping` responde
404. **Ningún otro spec depende todavía del harness**, a propósito: su ausencia
no puede costar un rojo. Con `app/Modules/E2E` presente —como en el boilerplate— corren; en un
derivado que no lo copie, se saltan solos.

## El guardia de errores: que un test verde signifique algo

Un test puede pasar sobre una pantalla rota. La aserción comprueba que el toast
aparece; nadie mira que, mientras tanto, un componente Alpine lanzó una
excepción, que una petición secundaria devolvió 500 o que la consola se llenó de
errores. El test queda verde y el bug llega a producción.

`tests/e2e/fixtures/error-guard.ts` se engancha a los cuatro canales que
delatan eso, y `fixtures/index.ts` lo monta en **todos** los tests con un
`test.beforeEach` — los pidan o no, porque justo los que no lo piden son los que
necesitan que alguien mire.

| Canal | Evento de Playwright | Tipo | ¿Tumba el test? |
| --- | --- | --- | --- |
| Excepción JS no capturada | `pageerror` | `excepcion` | **Sí** |
| `console.error(...)` | `console` (type `error`) | `consola` | **Sí** |
| Respuesta 5xx | `response` | `http` | **Sí** |
| Respuesta 4xx | `response` | `http` | No — se anota como aviso |
| Petición que no llegó a salir | `requestfailed` | `request-fallido` | No — aviso |

Los 4xx son avisos a propósito: la matriz de acceso **vive** de los 403, y una
lista de deudas anotadas en el reporte es más útil que cuarenta rojos que nadie
mira. `errores.avisos()` los devuelve, y `specs/access/smoke.spec.ts` los
adjunta como anotación del test.

Cuando salta, el mensaje es éste:

    La pantalla funcionó pero se rompió por debajo:
      · [excepcion] TypeError: Cannot read properties of undefined
        en http://localhost:8010/users

### El ruido conocido, y por qué está documentado

Hay cosas que aparecen en la consola de un Chromium sano y no dicen nada de la
salud de la aplicación. Están en `RUIDO_CONOCIDO`, **cada una con su porqué**:
una lista de patrones sin explicación acaba tragándose bugs de verdad.

| Patrón | Por qué se ignora |
| --- | --- |
| `favicon.ico` | El navegador lo pide solo y el boilerplate no publica uno. |
| `net::ERR_ABORTED` | Una navegación cancela lo que hubiera en vuelo. Con Livewire pasa constantemente: se pulsa «Guardar», el componente redirige y el `fetch` de `/livewire/update` que venía detrás muere a medias. |
| `Failed to fetch` · `AbortError` · `signal is aborted` | La otra cara de lo mismo, vista desde el JS: la promesa del `fetch` cancelado se rechaza y Livewire lo escupe por consola. |
| `chrome-extension:` | Extensiones del navegador, ajenas a la aplicación. |
| `[vite] connecting` | Chatter del cliente de Vite en modo dev (la suite compila, pero por si acaso). |

**El error del canal de consola lleva pegada la URL del recurso**
(`msg.location().url`), porque sin ella un 404 de favicon llega como
«Failed to load resource» a secas y no hay patrón que lo distinga de uno de
verdad.

### Apagarlo para un test que rompe a propósito

Dos formas, y las dos obligan a decir **qué** se tolera, nunca «todo»:

```ts
// Declarativo, para un describe o un archivo entero:
test.use({ tolerarErrores: ['KORE-E2E-001', '500 POST /livewire/update'] });

// Imperativo, dentro de un test suelto:
test('…', async ({ page, errores }) => {
    errores.tolerar('KORE-E2E-001');
    …
});
```

**Cita siempre el identificador del hallazgo** de
[`tests/e2e/HALLAZGOS.md`](../../tests/e2e/HALLAZGOS.md) que lo justifica: una
tolerancia sin hallazgo es un bug silenciado, y el patrón con el identificador
dentro se puede buscar el día que se arregle.

Y una cosa que el guardia **no** hace: si el test ya falló por su cuenta, se
calla. Su error taparía la causa real.

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

npm run manual          # el manual de usuario: recorridos + capturas + Markdown
npm run manual:pdf      # el manual entero en un PDF (necesita Gotenberg)
```

El manual corre **aparte de la suite**, con su propia config y su propio puerto
(8110). Ver [`manual.md`](manual.md).

### `scripts/e2e.sh`, que es lo que corre `npm run e2e`

`npm run e2e` ya no llama a `playwright test` a pelo: llama a
`bash scripts/e2e.sh`, que hace cuatro cosas antes y pasa los argumentos tal
cual.

1. **Puerto.** Playwright reutiliza el servidor que encuentre
   (`reuseExistingServer`), y eso sólo sirve si el que hay es el nuestro. Un
   `artisan serve` huérfano de una corrida anterior arrastra la conexión a una
   SQLite que el script está a punto de borrar, así que se baja; cualquier otro
   proceso aborta con un mensaje que dice cuál es.

   Ojo con **quién** escucha: `artisan serve` no abre el socket él, lo abre el
   servidor embebido de PHP que lanza como hijo, y en `ps` eso se ve como
   `php -S localhost:8010 …/resources/server.php`. El patrón reconoce ese
   `server.php`, no la palabra «serve», y baja también al padre para que no
   levante otro hijo.

2. **Assets.** Si falta `public/build/manifest.json`, `npm run build`.
   `globalSetup` hace la misma comprobación; aquí se adelanta para que el fallo
   se vea antes de arrancar el navegador.

3. **Datos.** Llama a `scripts/e2e-seed.sh` si existe. Si no, siembra
   `globalSetup`, que además vacía el log de correo y los `storageState`. Nunca
   se saltan los dos.

4. **Playwright**, con los argumentos que le hayan pasado.

```bash
npm run e2e                            # lo normal
npm run e2e -- --repeat-each=2         # caza de flakiness
bash scripts/e2e.sh --ui               # panel interactivo
bash scripts/e2e.sh tests/e2e/specs/users

E2E_SKIP_SEED=1 bash scripts/e2e.sh    # sin resembrar (iterar rápido)
E2E_BUILD=1     bash scripts/e2e.sh    # fuerza npm run build
E2E_PORT=8011   bash scripts/e2e.sh    # otro puerto (ajusta APP_URL en .env.e2e)
```

El puerto sale de `APP_URL` en `.env.e2e`, que es la misma fuente que lee
`tests/e2e/support/env.ts`: una sola verdad, y no dos que se desincronizan.
`composer e2e` sigue funcionando: llama a `npm run e2e`. Ojo: `npm run e2e:ui`,
`e2e:headed` y `e2e:debug` llaman a `playwright test` directo y **no** pasan por
el script (ni puerto, ni build, ni sembrado); úsalos sobre una base ya sembrada
o lanza `bash scripts/e2e.sh --ui` en su lugar.

## Estructura

```
playwright.config.ts            # proyectos, webServer, reporters
playwright.manual.config.ts     # el manual de usuario (npm run manual)
scripts/e2e.sh                  # lo que corre `npm run e2e`
scripts/manual.sh               # lo que corre `npm run manual`
tests/e2e/
├── global-setup.ts             # build + reset de la base + limpieza de .auth
├── auth.setup.ts               # proyecto `setup`: login por la UI de cada rol
├── tsconfig.json               # sólo DX del editor; Vite no lo lee
├── FLUJOS.md                   # mapa de cobertura por flujo
├── HALLAZGOS.md                # lo que la suite ha encontrado (KORE-E2E-###)
├── fixtures/
│   ├── index.ts                # el `test` de la suite — IMPORTA DE AQUÍ
│   ├── error-guard.ts          # vigilante de excepciones JS, 5xx y consola
│   ├── livewire.ts             # hidratación, contador en vuelo y round-trip
│   ├── access-map.ts           # matriz ruta × perfil (RBAC y smoke salen de aquí)
│   └── harness.ts              # cliente de /__e2e__/* (app/Modules/E2E)
├── pages/                      # page objects
│   ├── LoginPage.ts  RegisterPage.ts  ForgotPasswordPage.ts  MagicLinkPage.ts
│   ├── DashboardPage.ts  UsersIndexPage.ts  UserFormPage.ts  DocsPage.ts
│   └── PasskeysPage.ts
├── support/
│   ├── env.ts                  # lee .env.e2e (baseURL, rutas)
│   ├── users.ts                # cuentas sembradas + rutas de storageState
│   ├── data.ts                 # uniqueEmail() / uniqueName()
│   ├── actions.ts              # createUserViaUi()
│   ├── mail-log.ts             # lee el código OTP de storage/logs/e2e-mail.log
│   └── webauthn.ts             # autenticador WebAuthn virtual (CDP)
├── manual/                     # el manual de usuario — ver quality/manual.md
│   ├── setup.ts                # comprueba harness y base antes de fotografiar
│   ├── teardown.ts             # rehace docs/manual/README.md
│   ├── fixtures/{guia.ts,rutas.ts}
│   └── 01-usuarios.guia.ts     # guía de ejemplo
└── specs/
    ├── access/{rbac,smoke}.spec.ts      # generados desde access-map.ts
    ├── harness/harness.spec.ts          # se salta si no está app/Modules/E2E
    ├── smoke/landing.spec.ts
    ├── auth/{login,register,forgot-password,magic-link,passkeys,invitations}.spec.ts
    ├── users/{index,create,edit,delete,dashboard,account-status,avatar}.spec.ts
    └── docs/{smoke,navigation,authorization}.spec.ts
```

`support/livewire.ts` **ya no existe**: se absorbió en `fixtures/livewire.ts`,
que conserva `waitForLivewireReady` y `conRoundTrip` (con `withLivewireRoundTrip`
como alias, misma semántica).

`specs/auth/protected-routes.spec.ts` **tampoco**: sus seis tests eran la
matriz de `/users` y `/users/create` escrita a mano, y `specs/access/rbac.spec.ts`
la cubre entera y con más perfiles.

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

Por orden de preferencia: `getByRole` → `getByLabel` → `getByPlaceholder` → `getByText`. Cuatro trampas reales de este stack:

1. **`getByLabel` también mira `aria-label`.** El ojo de `<x-kore::password>` se llama "Mostrar la contraseña", así que `getByLabel('Contraseña')` devuelve 4 elementos. Usa `{ exact: true }`.
2. **El asterisco de `required` va dentro del `<label>`.** En `/register` la etiqueta es literalmente `Contraseña *`.
3. **Con expresión regular, `getByLabel` NO normaliza los espacios** (compara contra el `textContent` crudo, con sus saltos de línea). Prefiere cadena + `{ exact: true }`.
4. **Un botón nuevo puede romper el locator de otro.** `getByRole('button',
   { name: 'Entrar' })` empezó a devolver dos elementos el día que `/login`
   ganó «Entrar con passkey»: `getByRole` casa por *substring*. Cuando el
   nombre de un botón es prefijo de otro, `{ exact: true }` no es opcional.

**No se tocan las vistas Blade para meter `data-testid`.** Si un elemento no se puede localizar de forma accesible, se usa un selector CSS estable, se comenta el porqué en el page object y se anota como candidato a mejora de accesibilidad.

### Esperar a Livewire, que es como se cumple R38 sin sufrir

R38 prohíbe `page.waitForTimeout()`. La regla es fácil de aceptar y difícil de
cumplir cuando la interacción **no deja nada observable**: un `wire:model.live`
cuyo re-render no altera un píxel, un `expect(...).toHaveCount(0)` que hay que
comprobar *después* de que el servidor conteste. Ahí es donde uno pone el
`sleep(500)`.

`tests/e2e/fixtures/livewire.ts` da tres piezas para no necesitarlo:

| Helper | Qué espera | Cuándo |
| --- | --- | --- |
| `waitForLivewireReady(page)` | Que Livewire haya **hidratado** (`initialRenderIsFinished` y al menos un componente montado) | Antes de escribir en un `wire:model`, y después de cualquier navegación |
| `esperarLivewire(page)` | Que no quede **ninguna petición en vuelo** | Después de una acción, para comprobar que algo desapareció o que no pasó nada |
| `conRoundTrip(page, accion)` | La respuesta de `/livewire/update` de **esa** acción, y luego el contador a cero | Cuando la acción no deja cambio observable |

`conRoundTrip` es el nombre nuevo de `withLivewireRoundTrip`, que se conserva
como alias porque los page objects ya lo citan así.

#### Cómo funciona el contador

`instrumentarLivewire()` instala un `addInitScript` que, en `livewire:init`,
engancha el hook `request` del propio Livewire y lleva la cuenta:

```ts
livewire?.hook?.('request', ({ succeed, fail }) => {
    window.__livewireEnVuelo = (window.__livewireEnVuelo ?? 0) + 1;

    const terminar = () => {
        window.__livewireEnVuelo = Math.max(0, (window.__livewireEnVuelo ?? 1) - 1);
    };

    // Los dos finales posibles. Se descuenta pase lo que pase, o el contador
    // se queda clavado y la espera no vuelve nunca.
    succeed?.(terminar);
    fail?.(terminar);
});
```

Tres detalles que no son adorno:

- **`livewire:init` es el único hueco que sirve.** Ahí `window.Livewire` ya
  existe pero todavía no ha arrancado: un hook registrado más tarde se perdería
  la primera petición.
- **La fixture `page` está sobrescrita** para llamar a `instrumentarLivewire()`
  antes de nada. `addInitScript` sólo afecta a los documentos que se carguen
  *después*, así que instalarlo desde el propio test llegaría tarde. Las
  fixtures `asSuperadmin`, `asEditor`… abren su propio contexto y lo repiten a
  mano.
- **`esperarLivewire()` se traga su propio timeout.** Una pantalla sin Livewire
  —`/`, `/docs`, `/health`— no tiene nada que esperar, y eso no es un fallo del
  test. Lo que falla es la aserción que venga después.

#### Y sigue siendo mejor esperar al cambio observable

Cuando hay un toast, una fila nueva o una URL, la aserción correspondiente es
siempre preferible: describe la **intención**, no la fontanería. Estos tres
helpers son para cuando no la hay.

### Estabilidad

- **Prohibido `page.waitForTimeout()`.** Se espera a un cambio observable: un toast, una fila, una URL, un `toHaveCount`.
- **Espera a Livewire, no al reloj**: `waitForLivewireReady()` antes de escribir en un `wire:model`, `esperarLivewire()` después de una acción sin cambio visible y `conRoundTrip()` para la ida y vuelta de una acción concreta. Ver «Esperar a Livewire», justo arriba.

Las rutas de passkeys tienen su propio limiter (`throttle:passkeys`, 30/min), y
para un invitado la clave es la **IP**: el cubo lo comparten todos los workers.
Con los dos specs de ceremonia que hay sobra de largo, pero un spec que
repitiera el login con passkey en bucle lo agotaría.

### WebAuthn: el autenticador virtual

Las passkeys son el único flujo del boilerplate que **no se puede probar sin
navegador y tampoco con uno normal**: la ceremonia la resuelve el sistema
operativo (Touch ID, Windows Hello, una llave USB) y ese diálogo vive fuera de
la página, donde Playwright no llega.

La salida es el **autenticador virtual de Chrome DevTools Protocol**: un
dispositivo de mentira que genera claves reales y firma de verdad. El servidor
verifica la atestación exactamente igual que la de un dispositivo físico, así
que el test ejercita el camino completo, no una simulación.

Vive en `tests/e2e/support/webauthn.ts`:

```ts
const cdp = await page.context().newCDPSession(page);

await cdp.send('WebAuthn.enable');
await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
    },
});
```

Los cuatro flags no son decorativos: `laravel/passkeys` pide
`residentKey: required` y `userVerification: required`, así que sin
`hasResidentKey` el registro falla con `NotSupportedError`, y sin
`hasUserVerification` + `isUserVerified` el autenticador no puede afirmar que
verificó a nadie. `automaticPresenceSimulation` sustituye al dedo que nadie va a
poner.

Tres cosas que hay que saber:

- **El autenticador cuelga del target de esa `page`.** Sobrevive a las
  navegaciones —registrar, cerrar sesión y volver a entrar con la passkey es un
  solo test— y muere con ella. Un `page` nuevo (o una fixture `asX`, que abre su
  propio contexto) necesita el suyo.
- **`credentials()` es una aserción de verdad.** `WebAuthn.getCredentials`
  devuelve lo que el autenticador guarda; comprobarlo distingue «la UI pintó una
  fila» de «existe una credencial».
- **El RP id manda sobre el `baseURL`.** Ver el punto siguiente.

### Por qué `.env.e2e` usa `localhost` y no `127.0.0.1`

El *relying party id* de WebAuthn tiene que ser un **dominio**, y Chrome rechaza
los literales IP con `The relying party ID is not a registrable domain suffix
of, nor equal to the current domain`. Con `APP_URL=http://127.0.0.1:8010` la
suite no podría registrar ni usar una passkey.

`.env.e2e` apunta por eso a `http://localhost:8010`, que además es un origen
**potencialmente seguro** para el navegador: WebAuthn exige contexto seguro y
`localhost` es la única excepción a `https://`, así que la suite funciona sin
TLS. De ahí salen el `--host` y el `--port` del `webServer`
(`tests/e2e/support/env.ts`), así que no hay nada más que cambiar.

Si un derivado mueve la suite a otro host, la regla es la misma: **un dominio, y
`https://` salvo `localhost`**.

### Qué debe cubrir un módulo nuevo

Como mínimo:

1. **Una entrada por pantalla en `tests/e2e/fixtures/access-map.ts`**, con su
   `heading` y sus cinco perfiles. Con eso el módulo ya tiene su smoke y su
   matriz de autorización sin escribir un test — y es obligatorio: lo verifica
   `kore:arch:check`.
2. **Un happy path** en `tests/e2e/specs/{modulo}/`: el caso de uso central de
   punta a punta, con datos únicos creados por el propio test.
3. **Lo que el mapa no puede describir**: rutas con parámetro, acciones que se
   ocultan en la UI según el permiso, flujos de varios pasos.

Usa el skill `kore-e2e-test` (`.agents/skills/kore-e2e-test/`) para el
andamiaje.

## El otro consumidor de la suite: el manual de usuario

`tests/e2e/manual/` es un proyecto de Playwright aparte que **recorre la
aplicación y la fotografía**, y deja `docs/manual/` escrito: una guía en
Markdown por recorrido, con una captura por paso. No es una suite de tests: no
comprueba reglas, enseña caminos.

Lo que importa aquí es que **reutiliza esta suite**: el mismo `test` con su
guardia de errores, el mismo harness, `esperarLivewire()` y —sobre todo— los
mismos page objects. Cuando una pantalla cambia y hay que tocar
`UsersIndexPage`, el manual se regenera con la pantalla nueva sin que nadie lo
reescriba; y si el botón que un recorrido busca ya no existe, la generación
falla en vez de dejar una captura que miente.

`npm run manual`. El detalle —cómo se escribe una guía, qué se versiona y cómo
sale el PDF— está en [`manual.md`](manual.md).

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

## Los dos cuadernos de la suite

La suite no sólo pasa o falla: **encuentra cosas**. Y lo que encuentra se pierde
si no se apunta.

- [`tests/e2e/HALLAZGOS.md`](../../tests/e2e/HALLAZGOS.md) — todo bug, hueco o
  comportamiento que sorprende, con su `KORE-E2E-###`, su estado (🔴 abierto ·
  🟢 corregido · 🔵 documentado) y el test que lo revela. **Si un hallazgo no
  está en esta lista, no existe.** El identificador se cita desde el comentario
  del test y, cuando se corrige, desde el comentario del código. Trae plantilla.
- [`tests/e2e/FLUJOS.md`](../../tests/e2e/FLUJOS.md) — mapa de cobertura: qué se
  puede hacer en el boilerplate, quién lo hace, qué spec lo prueba y con qué
  marca (✅ · 🟡 · ⬜ · 🔒). Un flujo nuevo se apunta aquí *antes* de escribir su
  spec.

Los dos viven en `tests/e2e/` y no en `docs/`, a propósito: son cuadernos de
trabajo de la suite, se editan en el mismo commit que los tests y no pasan por
el índice de `docs/README.md` (R40). Se citan desde aquí para que se encuentren.

### El ciclo, de principio a fin

1. Un test falla —o pasa dejando avisos, o falla sólo con `--repeat-each`.
2. Se anota en `HALLAZGOS.md` con el siguiente `KORE-E2E-###`.
3. Si hay que dejar pasar el error para que la suite siga verde, la tolerancia
   **cita ese identificador**:
   `test.use({ tolerarErrores: ['KORE-E2E-007'] })`.
4. Cuando se arregla, el estado pasa a 🟢 y el identificador se queda en el
   comentario del código como cicatriz.

## Troubleshooting

| Síntoma | Causa y arreglo |
| --- | --- |
| `Unable to locate file in Vite manifest` | Falta `public/build/manifest.json`. `globalSetup` lo compila si no existe; si lo tienes obsoleto: `E2E_BUILD=1 npm run e2e` o `npm run build`. |
| El puerto 8010 está ocupado | En local Playwright **reutiliza** el servidor que encuentre (`reuseExistingServer`). Si el que hay no es el de E2E, mátalo: `lsof -ti :8010 \| xargs kill`. |
| `SQLSTATE... database is locked` o datos raros | Base corrupta: `rm -f database/e2e.sqlite*` y vuelve a correr (`globalSetup` la recrea). |
| `browserType.launch: Executable doesn't exist` | Faltan los navegadores: `npm run e2e:install` (en Linux/CI, `npm run e2e:install:ci`). |
| Todos los tests con rol fallan redirigiendo a `/login` | `storageState` caducado o de otra base. Borra `tests/e2e/.auth/` y vuelve a correr; `globalSetup` ya lo hace en cada corrida. |
| `Too many login attempts` en el proyecto `setup` | Rate limiter de Fortify (5/min por `email\|ip`). Ocurre si corres la suite muchas veces seguidas con muchos workers; espera un minuto o baja `--workers`. |
| Un test pasa suelto y falla en la suite o con `--repeat-each=2` | Casi siempre estado compartido o una carrera de hidratación: revisa que use `uniqueEmail()`, que filtre antes de contar filas y que no navegue ni escriba sin esperar a Livewire. |
| `SecurityError` o `InvalidDomainError` al registrar una passkey | El origen no sirve como *relying party id*: una IP (`127.0.0.1`) o un `http://` que no sea `localhost`. Revisa `APP_URL` en `.env.e2e`. |
| El registro de passkey falla con `NotSupportedError` | Al autenticador virtual le falta `hasResidentKey: true` (el paquete pide credenciales descubribles) o `hasUserVerification: true`. Ver `support/webauthn.ts`. |
| `La pantalla funcionó pero se rompió por debajo` | El guardia de errores. Las aserciones pasaron, pero hubo una excepción de JS, un 5xx o un error de consola: el mensaje los lista con su URL. **No lo silencies**: anótalo en `tests/e2e/HALLAZGOS.md` y, si hay que dejarlo pasar, `test.use({ tolerarErrores: ['KORE-E2E-###'] })` citando el identificador. |
| Un error de consola que es ruido y no está en la lista | `RUIDO_CONOCIDO` en `fixtures/error-guard.ts`, siempre con su porqué (ver «El ruido conocido»). |
| `[harness] Falló el montaje al …` | El fallo es del atrezzo, no de lo que el test probaba. Si `/__e2e__/ping` responde 404, `app/Modules/E2E` no está en este árbol y `specs/harness` debería saltarse solo. |
| `✋ Algo ocupa el puerto 8010 y no es el servidor de E2E` | `scripts/e2e.sh` baja los `artisan serve` huérfanos, pero se niega a matar nada más. El mensaje dice qué proceso es: bájalo, o `E2E_PORT=8011` (ajustando `APP_URL` en `.env.e2e`). |
| Un formulario se envía y la URL acaba con `?form.name=…&form.password=…` | KORE-E2E-007: Livewire no había hidratado, así que `wire:submit` no estaba enganchado y el navegador mandó el `<form>` de forma nativa como GET. Espera a `waitUntilReady()` antes de escribir y antes de guardar. |

## Workaround vigente: confirmar una row action

Éste es el fallo que justifica la suite entera (**R36**), y hoy está **resuelto y cubierto**.

El diálogo de confirmación de una **row action** del DataTable se construye en el cliente (`RowAction::buildKoreConfirmPayload`) y al aceptar emite `kore:confirm-callback`, pero `InteractsWithFeedback::handleConfirmCallback()` sólo ejecuta métodos previamente autorizados en `$koreConfirmable` — lista que rellena únicamente `Confirm::send()` en el servidor, camino que las row actions no recorren (las bulk actions sí). Con koreUi 2.2, borrar un usuario desde la fila no hacía **nada**: el listener descartaba la llamada y `TableUsers::confirmDelete()` nunca se invocaba.

El workaround vive en `TableUsers::hydrate()`, que añade `confirmDelete` a `$koreConfirmable` después de restaurar el snapshot y antes de despachar el listener. **Se quita en cuanto koreUi autorice las row actions por su cuenta.**

Lo importante para esta guía es *quién lo vio*: los tests de Livewire pasaban en verde porque invocan el método directamente, sin pasar por el diálogo del navegador. Sólo el E2E lo detectó. Por eso `specs/users/delete.spec.ts` cubre los cuatro casos del flujo —el diálogo se abre, cancelar no borra, **confirmar sí borra la fila** (`toHaveCount(0)` + toast) y la acción se oculta a quien no tiene `users.delete`— y no sólo la mitad que se puede comprobar sin navegador.
