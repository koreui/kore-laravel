import { expect, SEEDED_USERS, test } from '../../fixtures';
import { UsersIndexPage } from '../../pages/UsersIndexPage';

/**
 * Las aserciones filtran antes de contar.
 *
 * La tabla pagina de 25 en 25 y ordena por `created_at desc`, así que los
 * usuarios que otros specs crean en paralelo empujan a los sembrados hacia
 * abajo. Buscando por el dominio `e2e.test` —que sólo llevan las cuentas del
 * seeder; las que crean los tests usan `spec.test`— el listado queda acotado
 * y el test no depende de qué más esté corriendo.
 */
const SEEDED_DOMAIN = 'e2e.test';

test.describe('Users · listado', () => {
    test.use({ role: 'superadmin' });

    test('muestra las cuentas sembradas y oculta a los superadmin', async ({ page }) => {
        const users = new UsersIndexPage(page);

        await users.goto();
        await expect(users.heading).toBeVisible();

        await users.searchFor(SEEDED_DOMAIN);

        await expect(users.row(SEEDED_USERS.editor.email)).toBeVisible();
        await expect(users.row(SEEDED_USERS.viewer.email)).toBeVisible();
        await expect(users.row(SEEDED_USERS.member.email)).toBeVisible();

        // TableUsers::query() excluye el rol superadmin a propósito.
        await expect(users.row(SEEDED_USERS.superadmin.email)).toHaveCount(0);
        await expect(users.rows).toHaveCount(3);
    });

    test('el buscador filtra la tabla', async ({ page }) => {
        const users = new UsersIndexPage(page);

        await users.goto();
        await users.searchFor(SEEDED_DOMAIN);
        await expect(users.row(SEEDED_USERS.viewer.email)).toBeVisible();

        // `wire:model.live.debounce.300ms`: que desaparezca la fila que sobra
        // es el cambio observable que espera la aserción.
        await users.searchFor(SEEDED_USERS.editor.email);

        await expect(users.row(SEEDED_USERS.editor.email)).toBeVisible();
        await expect(users.row(SEEDED_USERS.viewer.email)).toHaveCount(0);
        await expect(users.rows).toHaveCount(1);
    });

    test('el botón "Nuevo usuario" lleva al formulario', async ({ page }) => {
        const users = new UsersIndexPage(page);

        await users.goto();
        await users.newUserButton.click();

        await expect(page).toHaveURL(/\/users\/create$/);
        await expect(page.getByRole('heading', { name: 'Crear usuario' })).toBeVisible();
    });
});
