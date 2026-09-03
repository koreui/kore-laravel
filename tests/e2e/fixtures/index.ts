import { existsSync } from 'node:fs';

import {
    expect,
    test as base,
    type Browser,
    type BrowserContextOptions,
    type Page,
} from '@playwright/test';

import { LoginPage } from '../pages/LoginPage';
import { baseURL } from '../support/env';
import { SEEDED_USERS, storageStateFor, type Role } from '../support/users';

/**
 * Base de todos los specs. Aporta dos cosas:
 *
 * 1. **La opción `role`.** Un `test.use({ role: 'superadmin' })` en el
 *    describe (o en el archivo entero) hace que el `page` de Playwright llegue
 *    ya autenticado, conservando trace, vídeo y screenshot automáticos:
 *
 *        test.describe('Users · listado', () => {
 *            test.use({ role: 'superadmin' });
 *            test('…', async ({ page }) => { … });
 *        });
 *
 *    Sin `role` el `page` es un invitado, que es lo que quieren los specs de
 *    landing, login y registro.
 *
 * 2. **Las fixtures `asSuperadmin`, `asEditor`, `asViewer`, `asMember`**, para
 *    el caso de necesitar DOS roles en el mismo test:
 *
 *        test('viewer no ve lo que editor sí', async ({ asViewer, asEditor }) => { … });
 *
 *    Contrapartida: son contextos creados a mano, así que sus artefactos no se
 *    adjuntan al reporte. Úsalas sólo cuando haga falta más de una sesión.
 *
 * Una sesión POR WORKER, no una global: ver `storageStateFor()`.
 */

type RoleOption = {
    /** Rol con el que llega autenticado el `page` del test. */
    role: Role | null;
};

type RolePages = {
    asSuperadmin: Page;
    asEditor: Page;
    asViewer: Page;
    asMember: Page;
};

type WorkerAuth = {
    /** Devuelve (creándola si hace falta) la sesión de ese rol para este worker. */
    sessionFor: (role: Role) => Promise<string>;
};

async function withRolePage(
    browser: Browser,
    contextOptions: BrowserContextOptions,
    storageState: string,
    use: (page: Page) => Promise<void>,
): Promise<void> {
    // `contextOptions` trae baseURL, viewport y demás del proyecto: sin
    // esparcirlo, un `page.goto('/users')` no sabría contra qué host resolver.
    const context = await browser.newContext({ ...contextOptions, storageState });

    try {
        await use(await context.newPage());
    } finally {
        await context.close();
    }
}

export const test = base.extend<RoleOption & RolePages, WorkerAuth>({
    role: [null, { option: true }],

    storageState: async ({ role, sessionFor }, use) => {
        await use(role === null ? undefined : await sessionFor(role));
    },

    sessionFor: [
        async ({ browser }, use, workerInfo) => {
            const created = new Map<Role, string>();

            const sessionFor = async (role: Role): Promise<string> => {
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
                    const context = await browser.newContext({ baseURL });
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

    asSuperadmin: async ({ browser, contextOptions, sessionFor }, use) => {
        await withRolePage(browser, contextOptions, await sessionFor('superadmin'), use);
    },
    asEditor: async ({ browser, contextOptions, sessionFor }, use) => {
        await withRolePage(browser, contextOptions, await sessionFor('editor'), use);
    },
    asViewer: async ({ browser, contextOptions, sessionFor }, use) => {
        await withRolePage(browser, contextOptions, await sessionFor('viewer'), use);
    },
    asMember: async ({ browser, contextOptions, sessionFor }, use) => {
        await withRolePage(browser, contextOptions, await sessionFor('member'), use);
    },
});

export { expect };
export { SEEDED_USERS, E2E_PASSWORD, type Role } from '../support/users';
