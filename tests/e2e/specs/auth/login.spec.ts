import { expect, test } from '../../fixtures';
import { DashboardPage } from '../../pages/DashboardPage';
import { LoginPage } from '../../pages/LoginPage';
import { createUserViaUi } from '../../support/actions';
import { uniqueEmail } from '../../support/data';

/**
 * Los tests que se autentican de verdad NO usan las cuentas sembradas: se
 * crean la suya por la UI de Users (que las deja con el email verificado).
 *
 * Motivo: el rate limiter de Fortify (FortifyServiceProvider) permite 5
 * intentos por minuto y por `email|ip`. Las cuentas del seeder ya gastan
 * cupo abriendo la sesión de cada worker de Playwright, así que un spec que
 * hiciera login con ellas —y más con `--repeat-each`— acabaría viendo "Too
 * many login attempts". Con un email único por test, cada uno tiene su propio
 * cubo.
 */
test.describe('Login', () => {
    test('con credenciales válidas entra al dashboard', async ({ page, asSuperadmin }) => {
        const user = await createUserViaUi(asSuperadmin);
        const login = new LoginPage(page);

        await login.goto();
        await login.login(user.email, user.password);

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { name: new RegExp(user.name) })).toBeVisible();
    });

    test('con credenciales inválidas muestra el error y no navega', async ({ page }) => {
        const login = new LoginPage(page);

        await login.goto();
        await login.login(uniqueEmail('desconocido'), 'contraseña-incorrecta');

        await expect(login.errorAlert).toBeVisible();
        await expect(page).toHaveURL(/\/login$/);
    });

    test('cerrar sesión devuelve a la landing y corta el acceso', async ({
        page,
        asSuperadmin,
    }) => {
        const user = await createUserViaUi(asSuperadmin);
        const login = new LoginPage(page);
        const dashboard = new DashboardPage(page);

        await login.goto();
        await login.login(user.email, user.password);
        await expect(page).toHaveURL(/\/dashboard$/);

        await dashboard.logOut();

        await expect(page).toHaveURL(/localhost:\d+\/$/);

        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('un invitado en /dashboard acaba en /login', async ({ page }) => {
        await page.goto('/dashboard');

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByRole('heading', { name: 'Bienvenido de vuelta' })).toBeVisible();
    });
});
