import { expect, test } from '../../fixtures';
import { ForgotPasswordPage } from '../../pages/ForgotPasswordPage';
import { RegisterPage } from '../../pages/RegisterPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';
import { SEEDED_INVITATION_CODE } from '../../support/users';

test.describe('Recuperar contraseña', () => {
    test('pedir el enlace muestra el estado de confirmación', async ({ page }) => {
        // El broker de reset tiene throttle de 60 s por email
        // (config/auth.php → passwords.users.throttle). Con una cuenta
        // sembrada, dos corridas seguidas —o un `--repeat-each`— verían
        // "Please wait before retrying" en vez de la confirmación. Así que el
        // test se fabrica su propia cuenta con un email único.
        const email = uniqueEmail('reset');
        const register = new RegisterPage(page);

        await register.goto();
        // El código es el que siembra `E2eSeeder`: con `AUTH_INVITATIONS=true`
        // (`.env.e2e`) el registro lo pide, y aquí el alta es sólo el atrezzo
        // para tener una cuenta con email único a la que pedirle el reset.
        await register.register({
            name: uniqueName('Reset'),
            email,
            password: STRONG_PASSWORD,
            invitationCode: SEEDED_INVITATION_CODE,
        });
        await expect(page).toHaveURL(/\/email\/verify$/);

        await page.context().clearCookies();

        const forgot = new ForgotPasswordPage(page);

        await forgot.goto();
        await forgot.request(email);

        // Fortify vuelve a /forgot-password con `session('status')`, que la
        // vista pinta como <x-kore::alert type="success" live="polite">
        // → role="status".
        await expect(page).toHaveURL(/\/forgot-password$/);
        await expect(forgot.statusAlert).toBeVisible();
        await expect(forgot.errorAlert).toHaveCount(0);
    });

    test('el enlace de vuelta lleva al login', async ({ page }) => {
        const forgot = new ForgotPasswordPage(page);

        await forgot.goto();
        await forgot.backToLoginLink.click();

        await expect(page).toHaveURL(/\/login$/);
    });
});
