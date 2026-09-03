import type { Locator, Page } from '@playwright/test';

import { waitForLivewireReady, withLivewireRoundTrip } from '../fixtures/livewire';

/**
 * `/users/create` y `/users/{id}/edit` — componente Livewire
 * `App\Modules\Users\Http\Livewire\FormComponent`.
 */
export class UserFormPage {
    readonly cardTitle: Locator;

    readonly name: Locator;

    readonly email: Locator;

    readonly password: Locator;

    readonly passwordConfirmation: Locator;

    /** `<x-kore::select … native>` → un `<select>` de verdad. */
    readonly role: Locator;

    readonly submit: Locator;

    readonly cancel: Locator;

    constructor(private readonly page: Page) {
        // `level: 3`: <x-kore::card> pinta su título como <h3>, y el layout
        // ya tiene un <h1> con el título de la página —que en /edit dice
        // exactamente lo mismo.
        this.cardTitle = page.getByRole('heading', { name: /^(Crear|Editar) usuario$/, level: 3 });
        // `exact` en todos: getByLabel busca en <label>, `aria-label`,
        // `aria-labelledby`, `placeholder` y `title`. Sin él, "Contraseña"
        // casaría también con "Confirmar contraseña" y con el aria-label del
        // ojo de <x-kore::password> ("Mostrar la contraseña").
        this.name = page.getByLabel('Nombre', { exact: true });
        this.email = page.getByLabel('Email', { exact: true });
        this.password = page.getByLabel('Contraseña', { exact: true });
        this.passwordConfirmation = page.getByLabel('Confirmar contraseña', { exact: true });
        this.role = page.getByLabel('Rol', { exact: true });
        this.submit = page.getByRole('button', { name: 'Guardar' });
        this.cancel = page.getByRole('link', { name: 'Cancelar' });
    }

    async gotoCreate(): Promise<void> {
        await this.page.goto('/users/create');
        await waitForLivewireReady(this.page);
    }

    /** Checkbox de un permiso del editor (label = `{Acción} {Módulo}`). */
    permission(label: string): Locator {
        return this.page.getByLabel(label, { exact: true });
    }

    /**
     * Mensaje de validación de un campo.
     *
     * koreUi pinta el error como `<p id="{fieldId}-error" role="alert">` (ver
     * `field.blade.php`), y `form-component.blade.php` fija los `id` a mano
     * (`form-name`, `form-email`, …), así que el selector es estable.
     */
    errorFor(field: 'name' | 'email' | 'password'): Locator {
        return this.page.locator(`#form-${field}-error`);
    }

    async fill(input: {
        name?: string;
        email?: string;
        password?: string;
        passwordConfirmation?: string;
    }): Promise<void> {
        if (input.name !== undefined) {
            await this.name.fill(input.name);
        }

        if (input.email !== undefined) {
            await this.email.fill(input.email);
        }

        if (input.password !== undefined) {
            await this.password.fill(input.password);
            await this.passwordConfirmation.fill(input.passwordConfirmation ?? input.password);
        }
    }

    /**
     * `form.role` va con `wire:model.live`: elegir dispara un round-trip que
     * no deja ningún cambio observable en pantalla, así que se espera a la
     * respuesta. Elígelo ANTES de escribir el resto: los demás campos son
     * diferidos y viajan con el envío.
     */
    async selectRole(label: 'Administrador' | 'Usuario'): Promise<void> {
        await withLivewireRoundTrip(this.page, async () => {
            await this.role.selectOption({ label });
        });
    }

    /**
     * KORE-E2E-007 · Espera a que el formulario esté vivo.
     *
     * Los inputs se renderizan **sin `value`**: es Livewire quien los rellena
     * desde el snapshot al hidratar. Antes de eso el formulario se ve en
     * blanco y, sobre todo, `wire:submit` no está enganchado — así que un
     * click en «Guardar» dispara el envío NATIVO del `<form>`, que es un GET
     * con todos los campos (contraseña incluida) en la barra de direcciones.
     *
     * Es lo que se llevaba por delante `specs/users/edit.spec.ts` una de cada
     * pocas corridas, y sólo con `--repeat-each`.
     */
    async waitUntilReady(): Promise<void> {
        await waitForLivewireReady(this.page);
    }

    /**
     * Envía el formulario.
     *
     * La espera de hidratación va **dentro**, y no en cada spec: llegar aquí
     * por la fila de la tabla (`clickRowAction('Editar')`) es una navegación
     * como cualquier otra, y nadie se acuerda de esperar después de una
     * navegación. Ver `waitUntilReady()`.
     */
    async save(): Promise<void> {
        await this.waitUntilReady();
        await this.submit.click();
    }
}
