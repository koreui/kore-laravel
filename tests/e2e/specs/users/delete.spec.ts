import { expect, SEEDED_USERS, test } from '../../fixtures';
import { UsersIndexPage } from '../../pages/UsersIndexPage';
import { createUserViaUi } from '../../support/actions';

test.describe('Users · borrar como superadmin', () => {
    test.use({ role: 'superadmin' });

    test('abre el diálogo de confirmación desde la fila', async ({ page }) => {
        const created = await createUserViaUi(page);
        const users = new UsersIndexPage(page);

        await users.focusOnRow(created.email);
        await users.clickRowAction(created.email, 'Eliminar');

        // RowAction::confirm() abre el diálogo del overlay manager de koreUi.
        await expect(users.confirmDialog).toBeVisible();
        await expect(users.confirmDialog).toContainText('¿Eliminar este usuario?');
        await expect(users.confirmButton).toBeVisible();
        await expect(users.cancelButton).toBeVisible();
    });

    test('cancelar la confirmación no borra nada', async ({ page }) => {
        const created = await createUserViaUi(page);
        const users = new UsersIndexPage(page);

        await users.focusOnRow(created.email);
        await users.clickRowAction(created.email, 'Eliminar');

        await expect(users.confirmDialog).toBeVisible();
        await users.cancelButton.click();

        await expect(users.confirmDialog).toBeHidden();
        await expect(users.row(created.email)).toBeVisible();
    });

    test('confirmar borra la fila', async ({ page }) => {
        // koreUi 2.2 no autoriza el wireMethod de las row actions con confirm();
        // TableUsers::hydrate() lo registra en $koreConfirmable como workaround.
        const created = await createUserViaUi(page);
        const users = new UsersIndexPage(page);

        await users.focusOnRow(created.email);
        await users.clickRowAction(created.email, 'Eliminar');
        await expect(users.confirmDialog).toBeVisible();

        await users.confirmButton.click();

        await expect(users.successToast).toBeVisible();
        await expect(users.row(created.email)).toHaveCount(0);
    });
});

test.describe('Users · borrar sin permiso', () => {
    test.use({ role: 'viewer' });

    test('viewer no ve ninguna acción de fila', async ({ page }) => {
        const users = new UsersIndexPage(page);

        await users.goto();
        const row = await users.focusOnRow(SEEDED_USERS.member.email);

        // Sin users.edit ni users.delete no queda ninguna acción visible, así
        // que la ActionColumn ni siquiera pinta el disparador del menú.
        await expect(row.getByRole('button', { name: 'Acciones' })).toHaveCount(0);
    });
});

test.describe('Users · borrar con permiso de edición pero no de borrado', () => {
    test.use({ role: 'editor' });

    test('editor ve "Editar" pero no "Eliminar"', async ({ page }) => {
        const users = new UsersIndexPage(page);

        await users.goto();
        await users.focusOnRow(SEEDED_USERS.member.email);

        await users.openRowActions(SEEDED_USERS.member.email);

        await expect(users.menuItem('Editar')).toBeVisible();
        await expect(users.menuItem('Eliminar')).toHaveCount(0);
    });
});
