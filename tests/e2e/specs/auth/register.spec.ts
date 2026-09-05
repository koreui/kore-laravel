import { expect, test } from '../../fixtures';
import { RegisterPage } from '../../pages/RegisterPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';
import { SEEDED_INVITATION_CODE } from '../../support/users';

/*
 * `.env.e2e` enciende `AUTH_INVITATIONS`, así que /register pide código. Todos
 * los casos de aquí usan el que siembra `E2eSeeder` —sin caducidad ni tope— y
 * siguen probando lo mismo que antes: lo que se ejercita es el registro, no la
 * invitación. El flujo de la invitación tiene su propio spec
 * (`auth/invitations.spec.ts`).
 */
test.describe('Registro', () => {
    test('un registro válido lleva a la pantalla de verificación de email', async ({ page }) => {
        const register = new RegisterPage(page);

        await register.goto();
        await register.register({
            name: uniqueName('Nuevo'),
            email: uniqueEmail('registro'),
            password: STRONG_PASSWORD,
            invitationCode: SEEDED_INVITATION_CODE,
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
            invitationCode: SEEDED_INVITATION_CODE,
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
            invitationCode: SEEDED_INVITATION_CODE,
        });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });

    test('un email ya registrado muestra error', async ({ page }) => {
        const register = new RegisterPage(page);
        const email = uniqueEmail('duplicado');

        await register.goto();
        await register.register({
            name: uniqueName('Primero'),
            email,
            password: STRONG_PASSWORD,
            invitationCode: SEEDED_INVITATION_CODE,
        });
        await expect(page).toHaveURL(/\/email\/verify$/);

        // Segundo intento con el mismo email, ya como invitado otra vez.
        await page.context().clearCookies();
        await register.goto();
        await register.register({
            name: uniqueName('Segundo'),
            email,
            password: STRONG_PASSWORD,
            invitationCode: SEEDED_INVITATION_CODE,
        });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });
});
