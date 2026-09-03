import { E2E_PASSWORD, expect, test } from '../../fixtures';
import { DashboardPage } from '../../pages/DashboardPage';
import { LoginPage } from '../../pages/LoginPage';
import { PasskeysPage } from '../../pages/PasskeysPage';
import { createUserViaUi } from '../../support/actions';
import { uniqueName } from '../../support/data';
import { addVirtualAuthenticator } from '../../support/webauthn';

/**
 * Passkeys (WebAuthn) · `AUTH_PASSKEYS=true` en `.env.e2e`.
 *
 * Este es el único flujo del boilerplate que **sólo** se puede probar en un
 * navegador: la ceremonia la resuelve el autenticador, no la aplicación. Se usa
 * el autenticador virtual de CDP (`support/webauthn.ts`), que firma de verdad;
 * el servidor valida la atestación igual que con un Touch ID real.
 *
 * `.env.e2e` apunta a `http://localhost:8010` y no a `127.0.0.1` justo por
 * esto: el «relying party id» de WebAuthn tiene que ser un dominio, y Chrome
 * rechaza los literales IP.
 */
test.describe('Passkeys · alta y login', () => {
    test('registra una passkey y luego entra con ella sin contraseña', async ({
        page,
        asSuperadmin,
    }) => {
        // Cuenta propia del test (R39): registrar una passkey MODIFICA al
        // usuario, y las cuentas sembradas son de sólo lectura.
        const user = await createUserViaUi(asSuperadmin);
        const deviceName = uniqueName('Portátil');

        // El autenticador vive en el target de `page`, así que sobrevive al
        // login, al registro, al logout y al login con passkey.
        const authenticator = await addVirtualAuthenticator(page);

        const login = new LoginPage(page);
        const dashboard = new DashboardPage(page);
        const passkeys = new PasskeysPage(page);

        await login.goto();
        await login.login(user.email, user.password);
        await expect(page).toHaveURL(/\/dashboard$/);

        // --- Alta -----------------------------------------------------------
        await passkeys.goto(user.password);
        await expect(passkeys.heading).toBeVisible();
        await expect(passkeys.emptyState).toBeVisible();

        await passkeys.registerPasskey(deviceName);

        // Cambio observable: la fila que pinta Livewire tras `$wire.$refresh()`.
        await expect(passkeys.row(deviceName)).toBeVisible();
        await expect(passkeys.emptyState).toHaveCount(0);

        // Y la credencial existe de verdad en el autenticador, no sólo en la UI.
        expect(await authenticator.credentials()).toHaveLength(1);

        // --- Login sin contraseña -------------------------------------------
        await dashboard.goto();
        await dashboard.logOut();
        await expect(page).toHaveURL(/localhost:\d+\/$/);

        await login.goto();
        await login.passkeyLogin.click();

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { name: new RegExp(user.name) })).toBeVisible();
    });

    test('elimina una passkey desde la lista', async ({ page, asSuperadmin }) => {
        const user = await createUserViaUi(asSuperadmin);
        const deviceName = uniqueName('Llave');

        await addVirtualAuthenticator(page);

        const login = new LoginPage(page);
        const passkeys = new PasskeysPage(page);

        await login.goto();
        await login.login(user.email, user.password);
        await expect(page).toHaveURL(/\/dashboard$/);

        await passkeys.goto(user.password);
        await passkeys.registerPasskey(deviceName);
        await expect(passkeys.row(deviceName)).toBeVisible();

        // `wire:confirm` abre el confirm() nativo, que Playwright descarta por
        // defecto: hay que aceptarlo explícitamente.
        page.once('dialog', (dialog) => void dialog.accept());
        await passkeys.deleteButton(deviceName).click();

        await expect(passkeys.row(deviceName)).toHaveCount(0);
        await expect(passkeys.emptyState).toBeVisible();
    });
});

test.describe('Passkeys · smoke', () => {
    test.use({ role: 'member' });

    test('la pantalla carga y lista vacío', async ({ page }) => {
        const passkeys = new PasskeysPage(page);

        await passkeys.goto(E2E_PASSWORD);

        await expect(page).toHaveURL(/\/user\/passkeys$/);
        await expect(passkeys.heading).toBeVisible();
        await expect(passkeys.name).toBeVisible();
        await expect(passkeys.register).toBeVisible();
        await expect(passkeys.emptyState).toBeVisible();
    });
});

test.describe('Passkeys · autorización', () => {
    test('un invitado en /user/passkeys acaba en /login', async ({ page }) => {
        await page.goto('/user/passkeys');

        await expect(page).toHaveURL(/\/login$/);
    });

    // `viewer` y no `member`: el smoke de arriba confirma la contraseña de
    // `member`, y la sesión de cada rol se comparte dentro del worker. Con otro
    // rol, la sesión llega aquí sin confirmar, que es justo lo que se prueba.
    test('un usuario autenticado tiene que confirmar la contraseña antes de entrar', async ({
        asViewer,
    }) => {
        await asViewer.goto('/user/passkeys');

        await expect(asViewer).toHaveURL(/\/user\/confirm-password$/);
        await expect(
            asViewer.getByRole('heading', { name: 'Confirma tu contraseña' }),
        ).toBeVisible();
    });

    test('el botón «Entrar con passkey» está en /login para un invitado', async ({ page }) => {
        const login = new LoginPage(page);

        await login.goto();

        await expect(login.passkeyLogin).toBeVisible();
    });
});
