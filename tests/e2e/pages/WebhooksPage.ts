import { expect, type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady, withLivewireRoundTrip } from '../fixtures/livewire';

/**
 * Las tres pantallas del módulo Webhooks: listado, alta y detalle.
 *
 * El listado es un `KoreDataTable`, así que hereda sus dos trampas (las mismas
 * que documenta `UsersIndexPage`): el menú de acciones se teletransporta a
 * `<body>` —los `menuitem` no cuelgan del `<tr>`— y el buscador es
 * `wire:model.live.debounce.300ms`, de modo que hay que dejar la tabla quieta
 * antes de abrir un desplegable.
 *
 * En el alta, las casillas de eventos llevan `id` propio por opción (koreUi
 * deduciría el mismo de `form.events` para todas), y por eso `getByLabel()`
 * distingue una de otra.
 */
export class WebhooksPage {
    readonly heading: Locator;

    readonly newEndpointButton: Locator;

    readonly search: Locator;

    readonly rows: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Webhooks', exact: true });
        this.newEndpointButton = page.getByRole('link', { name: 'Nuevo endpoint' });
        this.search = page.getByPlaceholder('Buscar...');
        this.rows = page.locator('tbody tr');
    }

    async goto(): Promise<void> {
        await this.page.goto('/webhooks');
        await waitForLivewireReady(this.page);
    }

    async gotoCreate(): Promise<void> {
        await this.page.goto('/webhooks/create');
        await waitForLivewireReady(this.page);
    }

    /** La fila que contiene ese texto (el nombre del endpoint, o su URL). */
    row(text: string): Locator {
        return this.page.locator('tbody tr').filter({ hasText: text });
    }

    /** Escribe en el buscador y espera al round-trip que dispara el debounce. */
    async searchFor(term: string): Promise<void> {
        await withLivewireRoundTrip(this.page, async () => {
            await this.search.fill(term);
        });
    }

    /** Deja la tabla con esa única fila y la devuelve. */
    async focusOnRow(text: string): Promise<Locator> {
        await this.searchFor(text);
        await expect(this.rows).toHaveCount(1);

        const row = this.row(text);
        await expect(row).toBeVisible();

        return row;
    }

    /**
     * Rellena el formulario de alta y guarda.
     *
     * `exact: true` en el botón: «Guardar» es prefijo de otros rótulos y sin él
     * el locator sería ambiguo el día que aparezca un «Guardar y salir».
     */
    async createEndpoint(name: string, url: string): Promise<void> {
        await this.page.getByLabel('Nombre').fill(name);
        await this.page.getByLabel('URL de destino').fill(url);

        // La primera casilla es el comodín: «Todos los eventos, incluidos los
        // que se añadan más adelante».
        await this.page
            .getByLabel('Todos los eventos, incluidos los que se añadan más adelante')
            .check();

        await this.page.getByRole('button', { name: 'Guardar', exact: true }).click();
    }
}
