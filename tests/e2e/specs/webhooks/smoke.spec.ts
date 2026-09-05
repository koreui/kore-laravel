import { expect, test } from '../../fixtures';
import { WebhooksPage } from '../../pages/WebhooksPage';

/**
 * Que la pantalla monta y que sus dos piezas de Livewire arrancan.
 *
 * El listado es un `KoreDataTable` y el alta un formulario con casillas
 * generadas desde `config/kore-webhooks.php`: las dos cosas se rompen sin que
 * lo note ningún test de Pest, porque el fallo está en el navegador.
 */
test.describe('Webhooks · smoke', () => {
    test.use({ role: 'superadmin' });

    test('el listado carga con su tabla', async ({ page }) => {
        const webhooks = new WebhooksPage(page);

        await webhooks.goto();

        await expect(webhooks.heading).toBeVisible();
        await expect(webhooks.search).toBeVisible();
        await expect(webhooks.newEndpointButton).toBeVisible();
    });

    test('el botón "Nuevo endpoint" lleva al formulario con el catálogo de eventos', async ({
        page,
    }) => {
        const webhooks = new WebhooksPage(page);

        await webhooks.goto();
        await webhooks.newEndpointButton.click();

        await expect(page).toHaveURL(/\/webhooks\/create$/);
        await expect(page.getByRole('heading', { name: 'Crear endpoint' })).toBeVisible();

        // El comodín y, al menos, el evento que publica el boilerplate.
        await expect(
            page.getByLabel('Todos los eventos, incluidos los que se añadan más adelante'),
        ).toBeVisible();
        await expect(page.getByText('auth.api_token.issued')).toBeVisible();
    });
});
