import { type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady } from '../fixtures/livewire';

/**
 * El panel `users.account-status-panel`, dentro de `/users/{id}/edit`.
 *
 * Sólo existe con `AUTH_INVITATIONS`, que `.env.e2e` enciende. El badge no es
 * un control de formulario ni un heading, así que se localiza por su texto —
 * que sale de `App\Core\Enums\AccountStatus::label()` y es uno de tres
 * valores cerrados.
 */
export class AccountStatusPanelPage {
    readonly cardTitle: Locator;

    readonly activateButton: Locator;

    readonly suspendButton: Locator;

    readonly successToast: Locator;

    constructor(private readonly page: Page) {
        this.cardTitle = this.page.getByRole('heading', { name: 'Estado de la cuenta', level: 3 });
        this.activateButton = page.getByRole('button', { name: 'Activar', exact: true });
        this.suspendButton = page.getByRole('button', { name: 'Suspender', exact: true });
        this.successToast = page.getByText('¡Listo!');
    }

    async gotoEdit(userId: number): Promise<void> {
        await this.page.goto(`/users/${userId}/edit`);
        await waitForLivewireReady(this.page);
    }

    /** El badge con la etiqueta del estado. */
    badge(label: 'Activa' | 'Suspendida' | 'Pendiente de activación'): Locator {
        return this.page.getByText(label, { exact: true });
    }
}
