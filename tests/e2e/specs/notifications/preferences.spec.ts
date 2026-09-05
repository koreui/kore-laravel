import { expect, test } from '../../fixtures';
import { NotificationsPage } from '../../pages/NotificationsPage';
import { uniqueEmail } from '../../support/data';

/**
 * Preferencias por categoría: apagar el correo de «Cuenta» y comprobar que
 * **sobrevive a una recarga**, que es lo que distingue una preferencia guardada
 * de un interruptor que sólo se movió en el navegador.
 *
 * Cuenta propia por test (R39): las preferencias son datos que este spec
 * escribe, y hacerlo sobre una cuenta de `E2eSeeder` cambiaría lo que ve
 * cualquier otro spec.
 *
 * El interruptor de push no se toca: sólo aparece con `DEVICES_ENABLED=true`, y
 * en `.env.e2e` está apagado. Que la pantalla lo esconda cuando no hay a dónde
 * mandar un push es parte de lo que se comprueba aquí.
 */
test.describe('Notifications · preferencias', () => {
    test('apagar el correo de una categoría persiste tras recargar', async ({ page, harness }) => {
        const email = uniqueEmail('preferencias');

        await harness.createUser({ role: 'Usuario', email, name: 'Dueña de sus avisos' });
        await harness.loginAs(email);

        const preferencias = new NotificationsPage(page);

        await preferencias.gotoPreferences();

        // El default del catálogo: `account` trae el correo encendido.
        await expect(preferencias.channelToggle('Cuenta', 'Correo')).toBeChecked();

        await preferencias.toggleChannel('Cuenta', 'Correo');

        await expect(preferencias.channelToggle('Cuenta', 'Correo')).not.toBeChecked();

        await preferencias.saveButton.click();

        await expect(preferencias.successToast).toBeVisible();

        // La prueba de verdad: recargar y encontrarlo apagado.
        await preferencias.gotoPreferences();

        await expect(preferencias.channelToggle('Cuenta', 'Correo')).not.toBeChecked();
        // La bandeja de esa categoría sigue encendida: se apagó un canal, no la
        // categoría entera.
        await expect(preferencias.channelToggle('Cuenta', 'En la bandeja')).toBeChecked();
    });

    test('no ofrece el interruptor de push sin el módulo de dispositivos', async ({
        page,
        harness,
    }) => {
        const email = uniqueEmail('preferencias-push');

        await harness.createUser({ role: 'Usuario', email, name: 'Dueña de sus avisos' });
        await harness.loginAs(email);

        const preferencias = new NotificationsPage(page);

        await preferencias.gotoPreferences();

        await expect(preferencias.channelToggle('Cuenta', 'Push')).toHaveCount(0);
        await expect(page.getByText('Canales disponibles')).toBeVisible();
    });

    test('cada categoría del catálogo tiene su bloque', async ({ page, harness }) => {
        const email = uniqueEmail('preferencias-catalogo');

        await harness.createUser({ role: 'Usuario', email, name: 'Dueña de sus avisos' });
        await harness.loginAs(email);

        const preferencias = new NotificationsPage(page);

        await preferencias.gotoPreferences();

        for (const categoria of ['Sistema', 'Cuenta', 'Actividad']) {
            await expect(page.getByRole('group', { name: categoria })).toBeVisible();
        }
    });
});
