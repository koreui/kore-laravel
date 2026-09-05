import { expect, test } from '../../fixtures';
import { InvitationsPage } from '../../pages/InvitationsPage';
import { RegisterPage } from '../../pages/RegisterPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';

/*
 * El flujo entero de una invitación, con `AUTH_INVITATIONS=true` (`.env.e2e`):
 * el superadmin reparte un código y un visitante entra con él.
 *
 * Cada test crea SU código (R39): la tabla no se resetea entre tests, y el
 * código sembrado por `E2eSeeder` es el que usan los specs de registro.
 */
test.describe('Invitaciones', () => {
    test.use({ role: 'superadmin' });

    test('el superadmin crea un código y lo ve en el listado', async ({ page }) => {
        const invitations = new InvitationsPage(page);
        const note = uniqueName('Campaña');

        await invitations.gotoCreate();
        const code = await invitations.create({ note, maxUses: 5 });

        expect(code).toMatch(/^[A-Z0-9]{8}$/);

        await invitations.gotoIndex();
        await expect(invitations.heading).toBeVisible();

        const row = invitations.row(code);
        await expect(row).toBeVisible();
        await expect(row).toContainText(note);
        await expect(row).toContainText('0 / 5');
        await expect(row).toContainText('Disponible');
    });

    test('revocar un código lo deja fuera de juego', async ({ page }) => {
        const invitations = new InvitationsPage(page);

        await invitations.gotoCreate();
        const code = await invitations.create({ note: uniqueName('Para revocar') });

        await invitations.gotoIndex();
        await expect(invitations.row(code)).toContainText('Disponible');

        await invitations.revoke(code);

        await expect(invitations.successToast).toBeVisible();
        await expect(invitations.row(code)).toContainText('Caducado');
    });
});

test.describe('Invitaciones · registro con el código', () => {
    test('un visitante se registra con un código recién repartido', async ({
        asSuperadmin,
        page,
    }) => {
        // El código lo reparte el superadmin desde su propia sesión; quien se
        // registra es el `page` sin sesión, que es el visitante de verdad.
        const invitations = new InvitationsPage(asSuperadmin);

        await invitations.gotoCreate();
        const code = await invitations.create({ note: uniqueName('Alta directa'), maxUses: 1 });

        const register = new RegisterPage(page);
        const email = uniqueEmail('invitado');

        await register.goto();
        await register.register({
            name: uniqueName('Invitado'),
            email,
            password: STRONG_PASSWORD,
            invitationCode: code,
        });

        // El User implementa MustVerifyEmail, así que Fortify rebota a la
        // pantalla de aviso: llegar ahí es haber creado la cuenta.
        await expect(page).toHaveURL(/\/email\/verify$/);

        // Y el código queda gastado: era de un solo uso.
        await invitations.gotoIndex();
        await expect(invitations.row(code)).toContainText('1 / 1');
        await expect(invitations.row(code)).toContainText('Agotado');
    });

    test('un código inventado no deja crear la cuenta', async ({ page }) => {
        const register = new RegisterPage(page);

        await register.goto();
        await register.register({
            name: uniqueName('Sin invitación'),
            email: uniqueEmail('sin-invitacion'),
            password: STRONG_PASSWORD,
            invitationCode: 'NOEXISTE',
        });

        await expect(page).toHaveURL(/\/register$/);
        await expect(register.errorAlert).toBeVisible();
    });
});
