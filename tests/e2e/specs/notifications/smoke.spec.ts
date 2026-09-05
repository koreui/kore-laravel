import { expect, test } from '../../fixtures';
import { NotificationsPage } from '../../pages/NotificationsPage';

/**
 * Smoke del módulo Notifications: las dos pantallas montan, la campana existe
 * en el encabezado y se navega de una a otra.
 *
 * `member` a propósito: la bandeja no tiene permisos propios —es algo que todo
 * el mundo tiene—, así que la cuenta sin ningún permiso del módulo Users es la
 * que mejor lo demuestra.
 */
test.describe('Notifications · smoke', () => {
    test.use({ role: 'member' });

    test('la bandeja carga con su heading y su título', async ({ page }) => {
        const bandeja = new NotificationsPage(page);

        await bandeja.goto();

        await expect(page).toHaveTitle('Notificaciones · kore-laravel');
        await expect(bandeja.heading).toBeVisible();
    });

    test('la campana está en el encabezado con el toggle encendido', async ({ page }) => {
        const bandeja = new NotificationsPage(page);

        await page.goto('/dashboard');

        await expect(bandeja.bell).toBeVisible();
    });

    test('se navega de la bandeja a las preferencias y de vuelta', async ({ page }) => {
        const bandeja = new NotificationsPage(page);

        await bandeja.goto();
        await bandeja.preferencesLink.click();

        await expect(page).toHaveURL(/\/notifications\/preferences$/);
        await expect(bandeja.preferencesHeading).toBeVisible();

        await bandeja.backLink.click();

        await expect(page).toHaveURL(/\/notifications$/);
        await expect(bandeja.heading).toBeVisible();
    });
});
