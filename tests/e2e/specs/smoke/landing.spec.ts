import { expect, test } from '../../fixtures';

test.describe('Landing pública', () => {
    test('carga con su heading y su título', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveTitle(/kore-laravel$/);
        await expect(
            page.getByRole('heading', { name: /Boilerplate Laravel/, level: 1 }),
        ).toBeVisible();
    });

    test('el CTA de registro lleva a /register', async ({ page }) => {
        await page.goto('/');

        await page.getByRole('link', { name: 'Empezar ahora' }).click();

        await expect(page).toHaveURL(/\/register$/);
        await expect(page.getByRole('heading', { name: 'Crear tu cuenta' })).toBeVisible();
    });

    test('el CTA de login lleva a /login', async ({ page }) => {
        await page.goto('/');

        // Hay dos enlaces "Iniciar sesión" (cabecera y hero): el del hero es
        // el que forma parte del flujo que interesa.
        await page.getByRole('link', { name: 'Iniciar sesión' }).last().click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByRole('heading', { name: 'Bienvenido de vuelta' })).toBeVisible();
    });

    test('/up responde 200', async ({ request }) => {
        const response = await request.get('/up');

        expect(response.status()).toBe(200);
    });
});
