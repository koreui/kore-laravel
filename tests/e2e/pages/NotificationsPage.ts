import { type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady } from '../fixtures/livewire';

/**
 * `/notifications` — la bandeja (`TableNotifications`) y `/notifications/preferences`
 * — los interruptores (`NotificationSettings`).
 *
 * Los dos viven en la misma clase porque son la misma pantalla en dos pestañas:
 * el botón «Preferencias» del encabezado lleva de una a otra y los tests suelen
 * recorrer las dos.
 *
 * Todos los locators son accesibles (R37): la bandeja es una lista de tarjetas
 * con botones nombrados, y los interruptores llevan `<label>` porque
 * `<x-kore::toggle>` los emite.
 */
export class NotificationsPage {
    readonly heading: Locator;

    readonly preferencesHeading: Locator;

    readonly preferencesLink: Locator;

    readonly backLink: Locator;

    readonly markAllButton: Locator;

    readonly onlyUnreadToggle: Locator;

    readonly categorySelect: Locator;

    readonly saveButton: Locator;

    readonly successToast: Locator;

    /** La campana del encabezado, que el layout pinta con el toggle encendido. */
    readonly bell: Locator;

    readonly items: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Notificaciones', exact: true });
        // Dos headings con el mismo texto: el `<h1>` del layout y el `<h3>` de
        // la tarjeta. El que prueba que la PANTALLA cargó es el primero — el
        // mismo caso que `/users/create` en el mapa de acceso.
        this.preferencesHeading = page
            .getByRole('heading', { name: 'Preferencias de notificación', exact: true })
            .first();
        this.preferencesLink = page.getByRole('link', { name: 'Preferencias' });
        this.backLink = page.getByRole('link', { name: 'Volver a la bandeja' });
        this.markAllButton = page.getByRole('button', { name: 'Marcar todas como leídas' });
        this.onlyUnreadToggle = page.getByLabel('Sólo sin leer');
        this.categorySelect = page.getByLabel('Categoría');
        this.saveButton = page.getByRole('button', { name: 'Guardar preferencias' });
        this.successToast = page.getByText('¡Listo!');
        // La campana declara su nombre accesible con aria-label, y ese nombre
        // cambia con el contador; el prefijo es lo estable.
        this.bell = page.getByRole('button', { name: /^Notificaciones/ });
        this.items = page.getByRole('listitem');
    }

    async goto(): Promise<void> {
        await this.page.goto('/notifications');
        await waitForLivewireReady(this.page);
    }

    async gotoPreferences(): Promise<void> {
        await this.page.goto('/notifications/preferences');
        await waitForLivewireReady(this.page);
    }

    /** La tarjeta que lleva ese título. */
    item(title: string): Locator {
        return this.items.filter({ hasText: title });
    }

    /** El botón «Marcar leída» de una tarjeta concreta. */
    markAsReadButton(title: string): Locator {
        return this.item(title).getByRole('button', { name: 'Marcar leída' });
    }

    /**
     * El interruptor de un canal en una categoría, para leer su estado.
     *
     * El `<fieldset>` de cada categoría lleva su `<legend>` como nombre
     * accesible, así que se filtra por él antes de buscar el switch — «Correo»
     * aparece una vez por categoría.
     */
    channelToggle(category: string, channel: string): Locator {
        return this.page
            .getByRole('group', { name: category })
            .getByRole('switch', { name: channel, exact: true });
    }

    /**
     * Cambia un interruptor.
     *
     * Se hace clic en la **etiqueta**, no en el `switch`: `<x-kore::toggle>`
     * pinta el `<input>` real con `sr-only` (el aspecto lo dibuja el `peer` de
     * Tailwind), así que para Playwright no es un elemento sobre el que se
     * pueda pulsar y `check()` se queda esperando a que sea visible. Es
     * exactamente lo que hace una persona: pulsar el texto del interruptor.
     */
    async toggleChannel(category: string, channel: string): Promise<void> {
        await this.page
            .getByRole('group', { name: category })
            .getByText(channel, { exact: true })
            .click();
    }
}
