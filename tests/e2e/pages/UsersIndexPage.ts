import { expect, type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady, withLivewireRoundTrip } from '../fixtures/livewire';

/**
 * `/users` — vista `users::pages.index` con el `KoreDataTable` `TableUsers`.
 *
 * Dos detalles del DataTable que condicionan los locators:
 *
 * - El menú de acciones de cada fila se teletransporta a `<body>`
 *   (`<template x-teleport="body">`), así que los `menuitem` NO cuelgan del
 *   `<tr>`. Todas las filas dejan el suyo en el DOM y sólo uno está visible a
 *   la vez → se filtra por visibilidad.
 * - El buscador es `wire:model.live.debounce.300ms`. Si se abre el menú
 *   mientras llega ese re-render, el morph se lleva por delante el
 *   desplegable recién abierto. Por eso `focusOnRow()` existe: filtra Y
 *   espera a que la tabla quede quieta.
 */
export class UsersIndexPage {
    readonly heading: Locator;

    readonly search: Locator;

    readonly newUserButton: Locator;

    readonly rows: Locator;

    /**
     * La tabla entera.
     *
     * No la usa ninguna aserción —las filas se comprueban una a una— sino el
     * manual de usuario, que recorta ahí sus capturas del listado. Vive en el
     * page object y no en el recorrido para que el día que el DataTable deje
     * de pintar un `<table>` haya un solo sitio que arreglar.
     */
    readonly table: Locator;

    readonly successToast: Locator;

    /** Diálogo de confirmación de koreUi (overlay manager). */
    readonly confirmDialog: Locator;

    readonly confirmButton: Locator;

    readonly cancelButton: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Usuarios', exact: true });
        // El buscador del DataTable no tiene <label>; su placeholder es el
        // único texto que lo identifica y es único en la página.
        this.search = page.getByPlaceholder('Buscar...');
        this.newUserButton = page.getByRole('link', { name: 'Nuevo usuario' });
        this.rows = page.locator('tbody tr');
        this.table = page.locator('table').first();
        this.successToast = page.getByText('¡Listo!');
        this.confirmDialog = page.getByRole('dialog');
        this.confirmButton = page.getByRole('button', { name: 'Confirmar' });
        this.cancelButton = page.getByRole('button', { name: 'Cancelar' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/users');
        await waitForLivewireReady(this.page);
    }

    /** La fila que contiene ese email. */
    row(email: string): Locator {
        return this.page.locator('tbody tr').filter({ hasText: email });
    }

    /** Botón que abre el menú de acciones de una fila. */
    actionsTrigger(email: string): Locator {
        return this.row(email).getByRole('button', { name: 'Acciones' });
    }

    /** Item del menú de acciones actualmente abierto. */
    menuItem(name: string): Locator {
        return this.page.getByRole('menuitem', { name }).filter({ visible: true });
    }

    /** Escribe en el buscador y espera al round-trip que dispara el debounce. */
    async searchFor(term: string): Promise<void> {
        await withLivewireRoundTrip(this.page, async () => {
            await this.search.fill(term);
        });
    }

    /**
     * Deja la tabla con esa única fila y devuelve su locator.
     *
     * Es además el punto de sincronización antes de tocar el menú de
     * acciones: hasta que el DataTable no ha repintado con el filtro, un
     * morph puede cerrar el desplegable a medio abrir.
     */
    async focusOnRow(email: string): Promise<Locator> {
        await this.searchFor(email);
        await expect(this.rows).toHaveCount(1);

        const row = this.row(email);
        await expect(row).toBeVisible();

        return row;
    }

    async openRowActions(email: string): Promise<void> {
        await this.actionsTrigger(email).click();
    }

    async clickRowAction(email: string, action: string): Promise<void> {
        await this.openRowActions(email);
        await this.menuItem(action).click();
    }
}
