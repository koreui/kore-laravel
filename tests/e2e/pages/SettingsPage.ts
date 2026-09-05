import type { Locator, Page } from '@playwright/test';

import { waitForLivewireReady } from '../fixtures/livewire';

/**
 * `/settings` — componente Livewire
 * `App\Modules\Platform\Http\Livewire\SettingsForm` (módulo Platform).
 *
 * Los campos de la pantalla no están escritos en ninguna parte: salen de
 * `config('kore-settings.editable')`. Por eso aquí sólo se exponen por nombre
 * los dos que el boilerplate garantiza —el nombre de la organización, que el
 * layout pinta, y la razón social— y el resto se alcanza con `field()`.
 */
export class SettingsPage {
    readonly heading: Locator;

    /** El `<h3>` de la tarjeta, distinto del `<h1>` del layout. */
    readonly cardTitle: Locator;

    readonly organizationName: Locator;

    readonly legalName: Locator;

    readonly submit: Locator;

    readonly successToast: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Ajustes', exact: true });
        this.cardTitle = page.getByRole('heading', { name: 'Ajustes de la instalación' });
        this.organizationName = this.field('Nombre de la organización');
        this.legalName = this.field('Razón social');
        // `exact`: el botón de cada campo dice «Restablecer», y sin él
        // «Guardar» casaría también con cualquier botón que empiece por ahí.
        this.submit = page.getByRole('button', { name: 'Guardar', exact: true });
        this.successToast = page.getByText('¡Listo!');
    }

    /**
     * El enlace de marca del sidebar, que en el layout lleva
     * `aria-label="{nombre de la organización}"`.
     *
     * Es la forma de comprobar que el ajuste llegó al layout **sin** mirar una
     * clase de CSS: el nombre accesible del enlace ES el dato (R37). Va con
     * `.first()` porque el shell de koreUi pinta el sidebar dos veces
     * (escritorio y móvil).
     */
    brandLink(name: string): Locator {
        return this.page.getByRole('link', { name, exact: true }).first();
    }

    async goto(): Promise<void> {
        await this.page.goto('/settings');
        await waitForLivewireReady(this.page);
    }

    /**
     * Un campo por su etiqueta en español (la de `kore-settings.editable`).
     *
     * La expresión regular hace dos trabajos, y los dos hacen falta:
     *
     * - Absorbe el `*` que koreUi añade dentro del `<label>` de un campo
     *   obligatorio, y los saltos de línea del Blade que lo rodean —con una
     *   regex, Playwright compara contra el texto **sin normalizar**.
     * - Va anclada, porque `getByLabel` también mira los `aria-label`: sin los
     *   anclajes, «Nombre de la organización» casaría además con el botón
     *   «Restablecer: Nombre de la organización» de ese mismo campo.
     */
    field(label: string): Locator {
        const escapado = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        return this.page.getByLabel(new RegExp(`^\\s*${escapado}\\s*\\*?\\s*$`));
    }

    /**
     * El botón «Restablecer» de un campo, identificado por su `aria-label`
     * («Restablecer: {etiqueta}»): los siete dicen lo mismo en pantalla.
     */
    restoreButton(label: string): Locator {
        return this.page.getByRole('button', { name: `Restablecer: ${label}` });
    }

    /**
     * Envía el formulario.
     *
     * La espera de hidratación va dentro por lo mismo que en `UserFormPage`
     * (KORE-E2E-007): antes de que Livewire hidrate, `wire:submit` no está
     * enganchado y el click dispara el envío NATIVO del `<form>`.
     */
    async save(): Promise<void> {
        await waitForLivewireReady(this.page);
        await this.submit.click();
    }
}
