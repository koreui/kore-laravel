import { expect, test } from '../../fixtures';

/**
 * Matriz de autorización del módulo Users.
 *
 * Se comprueba el STATUS de la navegación, no un texto de la página de error:
 * es lo que realmente devuelve el middleware `permission:` y no depende del
 * idioma ni del diseño de la vista 403.
 */
test.describe('Rutas protegidas · invitado', () => {
    for (const path of ['/users', '/users/create']) {
        test(`un invitado en ${path} acaba en /login`, async ({ page }) => {
            await page.goto(path);

            await expect(page).toHaveURL(/\/login$/);
        });
    }
});

test.describe('Rutas protegidas · por rol', () => {
    test('member no puede listar usuarios (403)', async ({ asMember }) => {
        const response = await asMember.goto('/users');

        expect(response?.status()).toBe(403);
    });

    test('viewer lista usuarios (200) pero no puede crear (403)', async ({ asViewer }) => {
        const index = await asViewer.goto('/users');
        expect(index?.status()).toBe(200);

        const create = await asViewer.goto('/users/create');
        expect(create?.status()).toBe(403);
    });

    test('editor sí puede abrir el formulario de creación (200)', async ({ asEditor }) => {
        const response = await asEditor.goto('/users/create');

        expect(response?.status()).toBe(200);
        await expect(asEditor.getByRole('heading', { name: 'Crear usuario' })).toBeVisible();
    });

    test('superadmin pasa por el Gate::before y entra a todo', async ({ asSuperadmin }) => {
        for (const path of ['/users', '/users/create']) {
            const response = await asSuperadmin.goto(path);
            expect(response?.status()).toBe(200);
        }
    });
});
