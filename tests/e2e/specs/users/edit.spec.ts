import { expect, test } from '../../fixtures';
import { UserFormPage } from '../../pages/UserFormPage';
import { UsersIndexPage } from '../../pages/UsersIndexPage';
import { createUserViaUi } from '../../support/actions';
import { uniqueName } from '../../support/data';

test.describe('Users · editar', () => {
    test.use({ role: 'superadmin' });

    test('cambia el nombre de un usuario creado en el propio test', async ({ page }) => {
        const created = await createUserViaUi(page);

        const users = new UsersIndexPage(page);
        const form = new UserFormPage(page);
        const newName = uniqueName('Renombrado');

        await users.focusOnRow(created.email);
        await users.clickRowAction(created.email, 'Editar');

        await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
        await form.waitUntilReady(); // KORE-E2E-007
        await expect(form.cardTitle).toHaveText('Editar usuario');
        await expect(form.name).toHaveValue(created.name);
        await expect(form.email).toHaveValue(created.email);

        await form.name.fill(newName);
        await form.save();

        await expect(users.successToast).toBeVisible();
        await expect(page).toHaveURL(/\/users$/);

        const row = await users.focusOnRow(created.email);
        await expect(row).toContainText(newName);
    });

    test('editar sin tocar la contraseña deja la cuenta usable', async ({ page }) => {
        const created = await createUserViaUi(page);

        const users = new UsersIndexPage(page);
        const form = new UserFormPage(page);

        await users.focusOnRow(created.email);
        await users.clickRowAction(created.email, 'Editar');

        // KORE-E2E-007 · Llegar por la fila es una navegación, y el formulario
        // no está vivo hasta que Livewire hidrata: escribir antes es escribir
        // en un input que nadie escucha, y el morph de la hidratación se lleva
        // por delante lo tecleado.
        await form.waitUntilReady();

        // El placeholder avisa de que dejarla vacía no la cambia.
        await expect(form.password).toHaveAttribute(
            'placeholder',
            'Dejar en blanco para no cambiar',
        );

        await form.name.fill(uniqueName('Sin password'));
        await form.save();

        await expect(page).toHaveURL(/\/users$/);
        await expect(users.successToast).toBeVisible();
    });
});
