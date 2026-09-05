import { expect, type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady } from '../fixtures/livewire';

/**
 * `/invitations` y `/invitations/create` — el `KoreDataTable`
 * `TableInvitations` y el componente `FormInvitation`.
 *
 * El código recién creado se lee del `<x-kore::clipboard>`, que en su variante
 * `input` pinta un `<input readonly>` etiquetado «Código». Se lee su `value` y
 * no un texto suelto porque es lo que la persona copia de verdad; el botón de
 * copiar usa la API de portapapeles del navegador, que en CI no está
 * disponible sin permisos y no es lo que este spec prueba.
 */
export class InvitationsPage {
    readonly heading: Locator;

    readonly newInvitationButton: Locator;

    readonly rows: Locator;

    /** Formulario de alta. */
    readonly role: Locator;

    readonly maxUses: Locator;

    readonly note: Locator;

    readonly submit: Locator;

    /** El campo del clipboard con el código recién creado. */
    readonly createdCode: Locator;

    readonly successToast: Locator;

    readonly confirmDialog: Locator;

    readonly confirmButton: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Invitaciones', exact: true });
        this.newInvitationButton = page.getByRole('link', { name: 'Nueva invitación' });
        this.rows = page.locator('tbody tr');
        this.role = page.getByLabel('Rol de quien se registre', { exact: true });
        this.maxUses = page.getByLabel('Límite de registros', { exact: true });
        this.note = page.getByLabel('Nota', { exact: true });
        this.submit = page.getByRole('button', { name: 'Crear invitación', exact: true });
        this.createdCode = page.getByLabel('Código', { exact: true });
        this.successToast = page.getByText('¡Listo!');
        this.confirmDialog = page.getByRole('dialog');
        this.confirmButton = page.getByRole('button', { name: 'Confirmar' });
    }

    async gotoIndex(): Promise<void> {
        await this.page.goto('/invitations');
        await waitForLivewireReady(this.page);
    }

    async gotoCreate(): Promise<void> {
        await this.page.goto('/invitations/create');
        await waitForLivewireReady(this.page);
    }

    /** La fila que contiene ese código. */
    row(code: string): Locator {
        return this.page.locator('tbody tr').filter({ hasText: code });
    }

    /**
     * Crea una invitación y devuelve el código que la pantalla enseña.
     *
     * Espera al `<input>` del clipboard —que sólo aparece tras guardar— antes
     * de leerlo: es el cambio observable que sustituye a un `waitForTimeout`
     * (R38).
     */
    async create(input: { note: string; maxUses?: number }): Promise<string> {
        await this.note.fill(input.note);

        if (input.maxUses !== undefined) {
            await this.maxUses.fill(String(input.maxUses));
        }

        await this.submit.click();

        await expect(this.createdCode).toBeVisible();

        return (await this.createdCode.inputValue()).trim();
    }

    /**
     * El botón «Revocar» de una fila.
     *
     * `ActionColumn::inline()` pinta cada acción como un `<button>` con
     * `aria-label`, no dentro de un desplegable: por eso se localiza por rol y
     * nombre dentro del `<tr>` y no hay menú que abrir antes.
     */
    revokeButton(code: string): Locator {
        return this.row(code).getByRole('button', { name: 'Revocar' });
    }

    /** Revoca un código y confirma el diálogo de koreUi. */
    async revoke(code: string): Promise<void> {
        await this.revokeButton(code).click();
        await expect(this.confirmDialog).toBeVisible();
        await this.confirmButton.click();
    }
}
