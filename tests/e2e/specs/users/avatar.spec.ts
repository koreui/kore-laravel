import { fileURLToPath } from 'node:url';

import { expect, test } from '../../fixtures';
import { UserFormPage } from '../../pages/UserFormPage';
import { UsersIndexPage } from '../../pages/UsersIndexPage';
import { createUserViaUi } from '../../support/actions';

/**
 * PNG de 1×1 generado con un script (ver `docs/modules/files.md`), no copiado
 * de ningún sitio: 69 bytes que el validador `image` acepta y que no arrastran
 * licencia ni metadatos.
 */
const AVATAR = fileURLToPath(new URL('../../fixtures/files/avatar.png', import.meta.url));

/**
 * El módulo Files visto desde el navegador: `.env.e2e` lo enciende con
 * `FILES_ENABLED=true`, así que la pantalla de edición pinta
 * `<x-files::slot-upload>` y la tabla su columna de avatar.
 *
 * Los dos controles del componente tienen `id` explícito (`slot-upload-input`
 * y `slot-upload-current`), y por eso se pueden alcanzar por su etiqueta: el
 * `<input type="file">` de koreUi es `sr-only`, pero `setInputFiles()` no
 * necesita que sea visible.
 */
test.describe('Users · avatar', () => {
    test.describe('como superadmin', () => {
        test.use({ role: 'superadmin' });

        test('sube la foto de un usuario y queda como la vigente', async ({ page }) => {
            const creado = await createUserViaUi(page);
            const users = new UsersIndexPage(page);
            const form = new UserFormPage(page);

            await users.focusOnRow(creado.email);
            await users.clickRowAction(creado.email, 'Editar');

            await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
            await form.waitUntilReady(); // KORE-E2E-007

            // Sin foto todavía: la zona de subida lleva la etiqueta del slot.
            const zona = page.getByLabel('Foto de perfil', { exact: true });
            await expect(zona).toBeAttached();

            await zona.setInputFiles(AVATAR);
            await page.getByRole('button', { name: 'Guardar foto' }).click();

            // Cambio observable, no reloj (R38): al haber archivo vigente el
            // componente pinta el bloque estático con su nombre y la zona de
            // subida pasa a llamarse «Sustituir por otro archivo».
            await expect(page.getByLabel('Sustituir por otro archivo', { exact: true }))
                .toBeAttached();
            await expect(page.getByText('avatar.png').first()).toBeVisible();
        });

        test('la foto subida aparece en la fila del listado', async ({ page }) => {
            const creado = await createUserViaUi(page);
            const users = new UsersIndexPage(page);
            const form = new UserFormPage(page);

            await users.focusOnRow(creado.email);
            await users.clickRowAction(creado.email, 'Editar');
            await form.waitUntilReady();

            await page.getByLabel('Foto de perfil', { exact: true }).setInputFiles(AVATAR);
            await page.getByRole('button', { name: 'Guardar foto' }).click();
            await expect(page.getByLabel('Sustituir por otro archivo', { exact: true }))
                .toBeAttached();

            await users.goto();
            const fila = await users.focusOnRow(creado.email);

            // `<x-kore::avatar>` pinta la imagen con `alt` = nombre del usuario,
            // así que la fila se comprueba por rol y nombre accesible (R37).
            await expect(fila.getByRole('img', { name: creado.name })).toBeVisible();
        });
    });

    test.describe('como viewer', () => {
        test.use({ role: 'viewer' });

        test('no ve la zona de subida porque no puede editar usuarios', async ({ page }) => {
            // `viewer` sólo tiene `users.view`: el `permission:users.edit` de la
            // ruta corta antes de llegar a la pantalla, así que no hay dónde
            // subir una foto.
            //
            // El id 1 es el `superadmin@e2e.test` de `E2eSeeder`, que siembra
            // sobre una base recién creada en cada `globalSetup`. Importa que
            // EXISTA: `SubstituteBindings` corre antes que el middleware de
            // permisos, así que un id inventado daría 404 y este test estaría
            // probando otra cosa.
            const respuesta = await page.goto('/users/1/edit');

            expect(respuesta?.status()).toBe(403);
            await expect(page.getByLabel('Foto de perfil', { exact: true })).toHaveCount(0);
        });
    });
});
