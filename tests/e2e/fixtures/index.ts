import { existsSync } from 'node:fs';

import {
    expect,
    test as base,
    type Browser,
    type BrowserContextOptions,
    type Page,
} from '@playwright/test';

import { LoginPage } from '../pages/LoginPage';
import { SEEDED_USERS, storageStateFor, type Role } from '../support/users';
import { ErrorGuard } from './error-guard';
import { Harness } from './harness';
import { instrumentarLivewire } from './livewire';

/**
 * Base de todos los specs. **Importa `test` y `expect` de aquí**, nunca de
 * `@playwright/test`: lo que este archivo añade no es opcional.
 *
 * ## 1. La opción `role`
 *
 * Un `test.use({ role: 'superadmin' })` en el describe (o en el archivo
 * entero) hace que el `page` de Playwright llegue ya autenticado,
 * conservando trace, vídeo y screenshot automáticos:
 *
 *     test.describe('Users · listado', () => {
 *         test.use({ role: 'superadmin' });
 *         test('…', async ({ page }) => { … });
 *     });
 *
 * Sin `role` el `page` es un invitado, que es lo que quieren los specs de
 * landing, login y registro.
 *
 * ## 2. Las fixtures `asSuperadmin`, `asEditor`, `asViewer`, `asMember`
 *
 * Para el caso de necesitar DOS roles en el mismo test:
 *
 *     test('viewer no ve lo que editor sí', async ({ asViewer, asEditor }) => { … });
 *
 * Contrapartida: son contextos creados a mano, así que sus artefactos no se
 * adjuntan al reporte. Úsalas sólo cuando haga falta más de una sesión. Sí
 * quedan vigiladas por el guardia de errores y sí llevan el contador de
 * Livewire.
 *
 * ## 3. El guardia de errores (`errores`), montado siempre
 *
 * Un `test.beforeEach` fuerza la fixture `errores` en todos los tests, los
 * pidan o no. Al terminar, si hubo una excepción de JavaScript, un 5xx o un
 * error de consola, **el test falla aunque sus aserciones hayan pasado**: un
 * verde sobre una pantalla rota no sirve de nada. Ver `error-guard.ts`.
 *
 * Para un test que provoca el error a propósito hay dos opt-out, y los dos
 * piden decir *qué* se tolera —nunca «todo»—:
 *
 *     // Declarativo, para un describe o un archivo entero:
 *     test.use({ tolerarErrores: ['KORE-E2E-001', '500 POST /livewire/update'] });
 *
 *     // Imperativo, dentro de un test suelto:
 *     test('…', async ({ page, errores }) => {
 *         errores.tolerar('KORE-E2E-001');
 *         …
 *     });
 *
 * Cita siempre el identificador del hallazgo de `tests/e2e/HALLAZGOS.md` que
 * lo justifica: una tolerancia sin hallazgo es un bug silenciado.
 *
 * ## 4. El harness (`harness`)
 *
 * Cliente de `/__e2e__/*` para montar estado sin UI. El backend es
 * `app/Modules/E2E`; hoy sólo lo usa `specs/harness/harness.spec.ts`, que se
 * salta solo si el módulo no está. Ver `harness.ts`.
 *
 * ## 5. El contador de Livewire
 *
 * La fixture `page` instala `instrumentarLivewire()` antes de la primera
 * navegación, así que `esperarLivewire()` funciona en cualquier spec sin que
 * nadie tenga que acordarse. Ver `livewire.ts`.
 *
 * Una sesión POR WORKER, no una global: ver `storageStateFor()`.
 */

type Opciones = {
    /** Rol con el que llega autenticado el `page` del test. */
    role: Role | null;
    /**
     * Patrones de error que este test declara tolerables. Vacío = el guardia
     * falla ante cualquier error grave.
     */
    tolerarErrores: string[];
};

type RolePages = {
    asSuperadmin: Page;
    asEditor: Page;
    asViewer: Page;
    asMember: Page;
};

type Fixtures = RolePages & {
    errores: ErrorGuard;
    harness: Harness;
};

type WorkerAuth = {
    /**
     * Devuelve (creándola si hace falta) la sesión de ese rol para este worker.
     *
     * `contextOptions` llega por parámetro y no de una importación porque la
     * fixture es de worker y las opciones del proyecto son de test: sin ellas,
     * el login se haría siempre contra el `APP_URL` de `.env.e2e` (8010) y el
     * manual —que corre su propio servidor en el 8110 con
     * `playwright.manual.config.ts`— iniciaría sesión en la aplicación
     * equivocada, o en ninguna.
     */
    sessionFor: (role: Role, contextOptions: BrowserContextOptions) => Promise<string>;
};

/**
 * Las opciones de contexto del proyecto, con su `baseURL` dentro.
 *
 * `contextOptions` NO trae el `baseURL`: Playwright lo expone como fixture
 * aparte y sólo lo mezcla al construir el `context` de la fixture `page`. Un
 * `browser.newContext(contextOptions)` a secas se queda sin él, y ahí cualquier
 * `page.goto('/webhooks')` revienta con «Invalid URL». Por eso todo contexto
 * hecho a mano en este archivo pasa por aquí.
 */
function opcionesDeContexto(
    contextOptions: BrowserContextOptions,
    baseURL: string | undefined,
): BrowserContextOptions {
    return baseURL === undefined ? contextOptions : { ...contextOptions, baseURL };
}

async function withRolePage(
    browser: Browser,
    contextOptions: BrowserContextOptions,
    storageState: string,
    errores: ErrorGuard,
    use: (page: Page) => Promise<void>,
): Promise<void> {
    // `contextOptions` ya viene mezclado con el `baseURL` del proyecto
    // (ver `opcionesDeContexto`): sin él, un `page.goto('/users')` no sabría
    // contra qué host resolver.
    const context = await browser.newContext({ ...contextOptions, storageState });

    try {
        const page = await context.newPage();

        // Las dos cosas que la fixture `page` hace por su cuenta y que aquí
        // hay que repetir a mano: el contador de Livewire y el guardia.
        await instrumentarLivewire(page);
        errores.vigilar(page);

        await use(page);
    } finally {
        await context.close();
    }
}

export const test = base.extend<Opciones & Fixtures, WorkerAuth>({
    role: [null, { option: true }],
    tolerarErrores: [[], { option: true }],

    storageState: async ({ role, sessionFor, contextOptions, baseURL }, use) => {
        await use(
            role === null
                ? undefined
                : await sessionFor(role, opcionesDeContexto(contextOptions, baseURL)),
        );
    },

    /**
     * El contador de Livewire tiene que instalarse ANTES del primer `goto`, y
     * `addInitScript` sólo afecta a los documentos que se carguen después.
     * Sobrescribir la fixture es la única forma de garantizar ese orden.
     */
    page: async ({ page }, use) => {
        await instrumentarLivewire(page);

        await use(page);
    },

    errores: async ({ page, tolerarErrores }, use, testInfo) => {
        const guard = new ErrorGuard(page, tolerarErrores);

        await use(guard);

        // Si el test ya falló, su error es el que importa: añadir el del
        // guardia encima sólo taparía la causa real.
        if (testInfo.status === 'failed' || testInfo.status === 'timedOut') {
            return;
        }

        const graves = guard.graves();

        if (graves.length > 0) {
            throw new Error(
                'La pantalla funcionó pero se rompió por debajo:\n' +
                    ErrorGuard.resumir(graves) +
                    '\n\nSi es un fallo conocido, anótalo en tests/e2e/HALLAZGOS.md y ' +
                    "tolera su patrón con test.use({ tolerarErrores: ['KORE-E2E-###'] }).",
            );
        }
    },

    harness: async ({ page }, use) => {
        await use(Harness.forPage(page));
    },

    sessionFor: [
        async ({ browser }, use, workerInfo) => {
            const created = new Map<Role, string>();

            const sessionFor = async (
                role: Role,
                contextOptions: BrowserContextOptions,
            ): Promise<string> => {
                const cached = created.get(role);

                if (cached !== undefined) {
                    return cached;
                }

                const file = storageStateFor(role, workerInfo.parallelIndex);

                // El worker 0 reaprovecha la sesión que dejó el proyecto
                // `setup`; el resto inician la suya la primera vez que la
                // piden, y la reutilizan durante toda la vida del worker.
                if (!existsSync(file)) {
                    const user = SEEDED_USERS[role];
                    const context = await browser.newContext(contextOptions);
                    const page = await context.newPage();
                    const login = new LoginPage(page);

                    await login.goto();
                    await login.login(user.email, user.password);
                    await expect(page).toHaveURL(/\/dashboard$/);

                    await context.storageState({ path: file });
                    await context.close();
                }

                created.set(role, file);

                return file;
            };

            await use(sessionFor);
        },
        { scope: 'worker' },
    ],

    asSuperadmin: async ({ browser, contextOptions, baseURL, sessionFor, errores }, use) => {
        const opciones = opcionesDeContexto(contextOptions, baseURL);

        await withRolePage(browser, opciones, await sessionFor('superadmin', opciones), errores, use);
    },
    asEditor: async ({ browser, contextOptions, baseURL, sessionFor, errores }, use) => {
        const opciones = opcionesDeContexto(contextOptions, baseURL);

        await withRolePage(browser, opciones, await sessionFor('editor', opciones), errores, use);
    },
    asViewer: async ({ browser, contextOptions, baseURL, sessionFor, errores }, use) => {
        const opciones = opcionesDeContexto(contextOptions, baseURL);

        await withRolePage(browser, opciones, await sessionFor('viewer', opciones), errores, use);
    },
    asMember: async ({ browser, contextOptions, baseURL, sessionFor, errores }, use) => {
        const opciones = opcionesDeContexto(contextOptions, baseURL);

        await withRolePage(browser, opciones, await sessionFor('member', opciones), errores, use);
    },
});

/**
 * Monta el guardia en TODOS los tests, los pidan o no.
 *
 * Sin esto, `errores` sólo existiría en los tests que la nombran entre sus
 * argumentos — y justo los que no la nombran son los que necesitan que
 * alguien mire.
 */
test.beforeEach(async ({ errores }) => {
    void errores;
});

export { expect };
export { ErrorGuard, type ErrorRecogido } from './error-guard';
export { Harness } from './harness';
export { conRoundTrip, esperarLivewire, waitForLivewireReady } from './livewire';
export { SEEDED_USERS, E2E_PASSWORD, type Role } from '../support/users';
