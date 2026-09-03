import { expect, test } from '../../fixtures';
import { RegisterPage } from '../../pages/RegisterPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';

test.describe('Registro', () => {
    test('un registro válido lleva a la pantalla de verificación de email', async ({ page }) => {
        const register = new RegisterPage(page);

        await register.goto();
        await register.register({
            name: uniqueName('Nuevo'),
            email: uniqueEmail('registro'),
            password: STRONG_PASSWORD,
        });

        // El User implementa MustVerifyEmail y /dashboard lleva middleware
        // `verified`, así que Fortify rebota a la pantalla de aviso.
        await expect(page).toHaveURL(/\/email\/verify$/);
        await expect(page.getByRole('heading', { name: 'Verifica tu correo' })).toBeVisible();
    });

    test('una contraseña corta muestra error y no registra', async ({ page }) => {
        const register = new RegisterPage(page);

        await register.goto();
        await register.register({
            name: uniqueName('Corta'),
            email: uniqueEmail('corta'),
            password: 'abc123',
        });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });

    test('una confirmación de contraseña distinta muestra error', async ({ page }) => {
        const register = new RegisterPage(page);

        await register.goto();
        await register.register({
            name: uniqueName('Distinta'),
            email: uniqueEmail('distinta'),
            password: STRONG_PASSWORD,
            passwordConfirmation: `${STRONG_PASSWORD}-otra`,
        });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });

    test('un email ya registrado muestra error', async ({ page }) => {
        const register = new RegisterPage(page);
        const email = uniqueEmail('duplicado');

        await register.goto();
        await register.register({ name: uniqueName('Primero'), email, password: STRONG_PASSWORD });
        await expect(page).toHaveURL(/\/email\/verify$/);

        // Segundo intento con el mismo email, ya como invitado otra vez.
        await page.context().clearCookies();
        await register.goto();
        await register.register({ name: uniqueName('Segundo'), email, password: STRONG_PASSWORD });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });
});
