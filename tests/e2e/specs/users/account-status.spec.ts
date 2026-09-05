import { expect, test } from '../../fixtures';
import { AccountStatusPanelPage } from '../../pages/AccountStatusPanelPage';
import { LoginPage } from '../../pages/LoginPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';

/*
 * El panel de estado de cuenta, con `AUTH_INVITATIONS=true` (`.env.e2e`).
 *
 * El usuario objetivo lo monta el harness y no la pantalla de alta: lo que se
 * prueba aquí es la palanca de estado, y crear la cuenta por la UI ya tiene su
 * spec (`users/create.spec.ts`).
 *
 * La guarda de «no puedes cambiar tu propio estado» no tiene spec aquí: la
 * tabla de usuarios oculta a los superadmin, así que desde esta sesión no se
 * puede llegar a la propia pantalla de edición. La cubren los tests de Pest del
 * componente y de la Action (`Users\Tests\Feature\AccountStatusPanelTest`).
 */
test.describe('Users · estado de la cuenta', () => {
    test.use({ role: 'superadmin' });

    test('suspender cierra el acceso y activar lo devuelve', async ({ page, harness }) => {
        const target = await harness.createUser({
            role: 'Usuario',
            email: uniqueEmail('estado'),
            name: uniqueName('Objetivo'),
            password: STRONG_PASSWORD,
        });

        const panel = new AccountStatusPanelPage(page);

        await panel.gotoEdit(target.id);
        await expect(panel.cardTitle).toBeVisible();
        await expect(panel.badge('Activa')).toBeVisible();

        await panel.suspendButton.click();
        await expect(panel.successToast).toBeVisible();
        await expect(panel.badge('Suspendida')).toBeVisible();

        await panel.activateButton.click();
        await expect(panel.successToast).toBeVisible();
        await expect(panel.badge('Activa')).toBeVisible();
    });

    test('una cuenta suspendida no puede entrar', async ({ page, asSuperadmin, harness }) => {
        const email = uniqueEmail('suspendida');

        const target = await harness.createUser({
            role: 'Usuario',
            email,
            name: uniqueName('Suspendida'),
            password: STRONG_PASSWORD,
        });

        const panel = new AccountStatusPanelPage(asSuperadmin);

        await panel.gotoEdit(target.id);
        await panel.suspendButton.click();
        await expect(panel.successToast).toBeVisible();

        // `page` de este describe lleva sesión de superadmin, así que se sale
        // primero: quien tiene que intentar entrar es esta cuenta y nadie más.
        await page.context().clearCookies();

        const login = new LoginPage(page);

        await login.goto();
        await login.login(email, STRONG_PASSWORD);

        // Fortify autentica y `EnsureAccountIsActive` cierra la sesión en la
        // primera pantalla protegida, devolviéndola al login con el motivo.
        await expect(page).toHaveURL(/\/login$/);
        // `.first()` porque el mensaje sale dos veces: la alerta general del
        // formulario y el error del campo `email`, que es donde `withErrors()`
        // lo deja. Es el mismo motivo por el que `RegisterPage.errorAlert`
        // también toma la primera.
        await expect(
            page
                .getByText('Tu cuenta está suspendida. Ponte en contacto con el administrador.')
                .first(),
        ).toBeVisible();
    });
});
