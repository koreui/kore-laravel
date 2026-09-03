import { expect, test } from '../../fixtures';
import { UserFormPage } from '../../pages/UserFormPage';
import { UsersIndexPage } from '../../pages/UsersIndexPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../../support/data';

test.describe('Users · crear', () => {
    test.use({ role: 'superadmin' });

    test('crea un usuario y lo deja en la tabla', async ({ page }) => {
        const form = new UserFormPage(page);
        const users = new UsersIndexPage(page);
        const name = uniqueName('Creado');
        const email = uniqueEmail('creado');

        await form.gotoCreate();
        await expect(form.cardTitle).toHaveText('Crear usuario');

        // `form.role` va con `wire:model.live`: se elige primero y
        // `selectRole()` espera a la respuesta. Los demás campos son
        // diferidos y viajan con el envío.
        await form.selectRole('Administrador');

        await form.fill({ name, email, password: STRONG_PASSWORD });
        await form.save();

        await expect(users.successToast).toBeVisible();
        await expect(page).toHaveURL(/\/users$/);

        const row = await users.focusOnRow(email);
        await expect(row).toContainText(name);
        await expect(row).toContainText('Administrador');
    });

    test('enviar el formulario vacío muestra errores de validación', async ({ page }) => {
        const form = new UserFormPage(page);

        await form.gotoCreate();
        await expect(form.cardTitle).toBeVisible();

        await form.save();

        await expect(form.errorFor('name')).toBeVisible();
        await expect(form.errorFor('email')).toBeVisible();
        await expect(form.errorFor('password')).toBeVisible();
        await expect(page).toHaveURL(/\/users\/create$/);
    });

    test('un email ya usado no pasa la validación', async ({ page }) => {
        const form = new UserFormPage(page);
        const email = uniqueEmail('repetido');

        await form.gotoCreate();
        await form.fill({ name: uniqueName('Primero'), email, password: STRONG_PASSWORD });
        await form.save();
        await expect(page).toHaveURL(/\/users$/);

        await form.gotoCreate();
        await form.fill({ name: uniqueName('Segundo'), email, password: STRONG_PASSWORD });
        await form.save();

        await expect(form.errorFor('email')).toBeVisible();
        await expect(page).toHaveURL(/\/users\/create$/);
    });
});
