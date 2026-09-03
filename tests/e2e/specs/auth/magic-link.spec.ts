import { expect, SEEDED_USERS, test } from '../../fixtures';
import { MagicLinkPage } from '../../pages/MagicLinkPage';
import { createUserViaUi } from '../../support/actions';
import { mailLogOffset, waitForOtpCode } from '../../support/mail-log';

test.describe('Magic link (código por email)', () => {
    test('pedir un código deja la pantalla en modo "código enviado"', async ({ page }) => {
        const magic = new MagicLinkPage(page);

        await magic.goto();
        await expect(magic.email).toBeVisible();

        await magic.requestCode(SEEDED_USERS.member.email);

        // Cambio observable del round-trip de Livewire: desaparece el botón de
        // enviar y aparecen las seis casillas del código.
        await expect(magic.digit(1)).toBeVisible();
        await expect(magic.verify).toBeVisible();
        await expect(magic.sendCode).toHaveCount(0);
        await expect(page.getByText(/está registrado, te enviamos un código de 6 dígitos/)).toBeVisible();
    });

    test('un email no registrado muestra exactamente el mismo estado (anti-enumeración)', async ({ page }) => {
        const magic = new MagicLinkPage(page);

        await magic.goto();
        await magic.requestCode('nadie-' + Date.now() + '@spec.test');

        await expect(magic.digit(1)).toBeVisible();
        await expect(magic.verify).toBeVisible();
        await expect(magic.sendCode).toHaveCount(0);
        await expect(page.getByText(/está registrado, te enviamos un código de 6 dígitos/)).toBeVisible();
    });

    test('un código incorrecto muestra el error y no autentica', async ({ page }) => {
        const magic = new MagicLinkPage(page);

        await magic.goto();
        await magic.requestCode(SEEDED_USERS.member.email);
        await expect(magic.digit(1)).toBeVisible();

        await magic.submitCode('000000');

        await expect(magic.errorAlert).toBeVisible();
        await expect(page).toHaveURL(/\/magic-link$/);
    });

    test('con el código real del email entra al dashboard', async ({ page, asSuperadmin }) => {
        // Cuenta propia y recién creada: `UserForm::store()` la deja con el
        // email verificado, y al ser un email único ningún otro test (ni un
        // `--repeat-each`) puede pisarle el código —
        // `only_one_active_one_time_password_per_user` está activo.
        const user = await createUserViaUi(asSuperadmin);

        const magic = new MagicLinkPage(page);

        await magic.goto();

        // Se anota el tamaño del log ANTES de pedir el código: sólo se lee lo
        // que se escriba después.
        const offset = mailLogOffset();

        await magic.requestCode(user.email);
        await expect(magic.digit(1)).toBeVisible();

        const code = await waitForOtpCode(user.email, offset);
        expect(code).toMatch(/^\d{6}$/);

        await magic.submitCode(code);

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { name: new RegExp(user.name) })).toBeVisible();
    });

    test('el enlace de vuelta lleva al login normal', async ({ page }) => {
        const magic = new MagicLinkPage(page);

        await magic.goto();
        await magic.backToLoginLink.click();

        await expect(page).toHaveURL(/\/login$/);
    });
});
