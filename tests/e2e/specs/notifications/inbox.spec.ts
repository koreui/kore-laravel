import { expect, test } from '../../fixtures';
import { NotificationsPage } from '../../pages/NotificationsPage';
import { uniqueEmail, uniqueName } from '../../support/data';

/**
 * Happy path de la bandeja: llega un aviso, se ve, se marca leído y el globo
 * de la campana se apaga.
 *
 * **Cada test crea su propia cuenta** (R39) en vez de usar una de `E2eSeeder`:
 * las notificaciones son datos que este spec escribe, y sembrarlas en una
 * cuenta compartida dejaría contadores distintos según el orden en que
 * corrieran los specs.
 *
 * La notificación se siembra por el harness (`POST /__e2e__/notify`), que por
 * dentro pasa por `App\Core\Contracts\Notifier` — el mismo contrato que usa la
 * aplicación. No hay ninguna pantalla del producto desde la que crear una
 * notificación a mano, así que el atrezzo es la única vía.
 */
test.describe('Notifications · bandeja', () => {
    test('un aviso recién llegado se ve, se abre y se marca como leído', async ({
        page,
        harness,
    }) => {
        const email = uniqueEmail('bandeja');
        const titulo = uniqueName('Aviso');

        await harness.createUser({ role: 'Usuario', email, name: 'Dueña de la bandeja' });
        await harness.notify({ email, title: titulo, body: 'Cuerpo del aviso.' });
        await harness.loginAs(email);

        const bandeja = new NotificationsPage(page);

        await bandeja.goto();

        // Está en la lista y sigue sin leer.
        await expect(bandeja.item(titulo)).toBeVisible();
        await expect(bandeja.markAsReadButton(titulo)).toBeVisible();

        // El globo de la campana lo cuenta.
        await expect(bandeja.bell).toHaveAccessibleName('Notificaciones: 1 sin leer');

        await bandeja.markAsReadButton(titulo).click();

        // Cambio observable (R38): el botón desaparece porque la tarjeta ya no
        // está sin leer, y el nombre de la campana vuelve al genérico.
        await expect(bandeja.markAsReadButton(titulo)).toHaveCount(0);
        await expect(bandeja.bell).toHaveAccessibleName('Notificaciones');
    });

    test('«marcar todas» vacía el contador de una vez', async ({ page, harness }) => {
        const email = uniqueEmail('bandeja-todas');

        await harness.createUser({ role: 'Usuario', email, name: 'Dueña de la bandeja' });
        await harness.notify({ email, title: uniqueName('Primero') });
        await harness.notify({ email, title: uniqueName('Segundo') });
        await harness.loginAs(email);

        const bandeja = new NotificationsPage(page);

        await bandeja.goto();

        await expect(bandeja.bell).toHaveAccessibleName('Notificaciones: 2 sin leer');

        await bandeja.markAllButton.click();

        await expect(bandeja.successToast).toBeVisible();
        await expect(bandeja.markAllButton).toHaveCount(0);
        await expect(bandeja.bell).toHaveAccessibleName('Notificaciones');
    });

    test('sólo se ve la bandeja propia', async ({ page, harness }) => {
        const mia = uniqueEmail('bandeja-mia');
        const ajena = uniqueEmail('bandeja-ajena');
        const mio = uniqueName('Mío');
        const suyo = uniqueName('Suyo');

        await harness.createUser({ role: 'Usuario', email: mia, name: 'Yo' });
        await harness.createUser({ role: 'Usuario', email: ajena, name: 'Otra persona' });
        await harness.notify({ email: mia, title: mio });
        await harness.notify({ email: ajena, title: suyo });
        await harness.loginAs(mia);

        const bandeja = new NotificationsPage(page);

        await bandeja.goto();

        await expect(bandeja.item(mio)).toBeVisible();
        await expect(bandeja.item(suyo)).toHaveCount(0);
    });
});
