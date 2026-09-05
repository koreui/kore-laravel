import { expect, test } from '../../fixtures';
import { SettingsPage } from '../../pages/SettingsPage';
import { uniqueName } from '../../support/data';

/**
 * Ajustes de la instalación (módulo Platform).
 *
 * ## Estos datos NO son propios del test, y no pueden serlo
 *
 * R39 pide que cada spec cree sus datos, pero los ajustes son un **singleton**:
 * hay una sola organización y cambiarla se ve en todas las pantallas. Lo que
 * hace este spec en su lugar es **devolverlo a su sitio**: escribe un nombre
 * único, comprueba que llega al layout y después pulsa «Restablecer», que borra
 * la fila y devuelve la clave a lo que dice `config/kore-settings.php`. Así el
 * spec siguiente encuentra la instalación como estaba.
 *
 * Ese `Restablecer` es también el happy path de la segunda mitad de la pantalla,
 * así que el rodeo no es sólo higiene.
 */
test.describe('Platform · ajustes', () => {
    test.use({ role: 'superadmin' });

    test('el superadmin cambia el nombre de la organización y lo ve en el layout', async ({
        page,
    }) => {
        const settings = new SettingsPage(page);
        const nombre = uniqueName('Organización');

        await settings.goto();
        await expect(settings.cardTitle).toBeVisible();

        await settings.organizationName.fill(nombre);
        await settings.save();

        await expect(settings.successToast).toBeVisible();

        // El View Composer del layout: el nombre viaja al sidebar de TODAS las
        // pantallas, no sólo a la de ajustes.
        await expect(settings.brandLink(nombre)).toBeVisible();

        await page.goto('/dashboard');
        await expect(settings.brandLink(nombre)).toBeVisible();

        // Y se deja como estaba: la fila se borra y la clave vuelve a su
        // defecto (`kore-laravel`, el APP_NAME de .env.e2e).
        await settings.goto();
        await settings.restoreButton('Nombre de la organización').click();

        await expect(settings.organizationName).toHaveValue('kore-laravel');

        /*
         * El sidebar vive FUERA del componente Livewire, así que `restore()`
         * —que no redirige— no lo repinta: el nombre viejo sigue ahí hasta la
         * siguiente carga. Por eso se recarga antes de comprobarlo, y por eso
         * `save()` sí redirige.
         */
        await settings.goto();
        await expect(settings.brandLink('kore-laravel')).toBeVisible();
    });

    test('el servidor rechaza un nombre de organización demasiado largo', async ({ page }) => {
        const settings = new SettingsPage(page);

        await settings.goto();

        await settings.organizationName.fill('K'.repeat(300));
        await settings.save();

        /*
         * La regla (`max:255`) sale del `type` declarado en
         * `kore-settings.editable`, no de nada escrito en el Form Object.
         *
         * Se prueba el largo y no el `required` ni el correo, y no es capricho:
         * de los tres, el largo es el ÚNICO que llega al servidor. El campo
         * obligatorio se pinta con `required` y el de correo con
         * `type="email"`, así que en los dos la validación nativa del navegador
         * corta el envío antes de que Livewire vea nada. `max` no tiene
         * equivalente nativo aquí —koreUi no emite `maxlength`—, y por eso es el
         * que demuestra que el servidor valida de verdad. El `required` y el
         * `email` los cubre `SettingsScreenTest` con Pest, donde no hay
         * navegador que se adelante.
         */
        await expect(page.locator('#setting-organization_name-error')).toBeVisible();
        await expect(settings.successToast).toBeHidden();
        await expect(page).toHaveURL(/\/settings$/);
    });
});

test.describe('Platform · ajustes · autorización', () => {
    test('un viewer no entra en /settings', async ({ asViewer }) => {
        /*
         * `page.request` y no `goto`: el guardia de errores cuenta como grave un
         * 4xx cargado con `goto`, y aquí el 403 es justo lo que se prueba. Es el
         * mismo criterio de `specs/access/rbac.spec.ts`.
         */
        const respuesta = await asViewer.request.get('/settings', {
            maxRedirects: 0,
            failOnStatusCode: false,
        });

        expect(respuesta.status()).toBe(403);
    });

    test('un invitado va al login', async ({ page }) => {
        const respuesta = await page.request.get('/settings', {
            maxRedirects: 0,
            failOnStatusCode: false,
        });

        expect(respuesta.status()).toBe(302);
        expect(respuesta.headers().location).toContain('/login');
    });
});
