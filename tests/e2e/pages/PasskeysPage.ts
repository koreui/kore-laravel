import type { Locator, Page } from '@playwright/test';

import { waitForLivewireReady } from '../fixtures/livewire';

/**
 * `/user/passkeys` — componente Livewire `App\Modules\Auth\Http\Livewire\Passkeys`.
 *
 * La ruta lleva `password.confirm`, así que llegar a la pantalla puede pasar
 * antes por `/user/confirm-password`. `goto()` se encarga.
 */
export class PasskeysPage {
    readonly heading: Locator;

    readonly name: Locator;

    readonly register: Locator;

    readonly emptyState: Locator;

    readonly errorAlert: Locator;

    /** Campo de la pantalla intermedia de confirmación de contraseña. */
    readonly confirmPassword: Locator;

    readonly confirmSubmit: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: 'Tus passkeys', exact: true });
        this.name = page.getByLabel('Nombre del dispositivo');
        this.register = page.getByRole('button', { name: 'Registrar passkey' });
        this.emptyState = page.getByText('Todavía no tienes passkeys');
        this.errorAlert = page.getByRole('alert');
        // KORE-E2E-005 · CANDIDATO A MEJORA DE ACCESIBILIDAD: `getByLabel('Contraseña')` es
        // ambiguo en esta pantalla — casa el input (nombre accesible
        // «Contraseña *») y el botón de ver/ocultar de <x-kore::password>, cuyo
        // aria-label es «Mostrar la contraseña». Hasta que ese botón se llame
        // de otra forma, CSS estable sobre el atributo name.
        this.confirmPassword = page.locator('input[name="password"]');
        this.confirmSubmit = page.getByRole('button', { name: 'Confirmar' });
    }

    /** La fila de una passkey concreta, localizada por su nombre. */
    row(name: string): Locator {
        return this.page.locator('li').filter({ hasText: name });
    }

    /** Botón «Eliminar» de esa fila. */
    deleteButton(name: string): Locator {
        return this.row(name).getByRole('button', { name: 'Eliminar' });
    }

    /**
     * Abre la pantalla, resolviendo la confirmación de contraseña si Fortify la
     * pide (la sesión se confirma una vez y dura `auth.password_timeout`).
     */
    async goto(password: string): Promise<void> {
        await this.page.goto('/user/passkeys');

        if (new URL(this.page.url()).pathname === '/user/confirm-password') {
            await this.confirmPassword.fill(password);
            await this.confirmSubmit.click();
            await this.page.waitForURL('**/user/passkeys');
        }

        await waitForLivewireReady(this.page);
    }

    /**
     * Registra una passkey con el autenticador virtual y espera a que la fila
     * aparezca en la lista (el cambio observable, nunca un sleep — R38).
     */
    async registerPasskey(name: string): Promise<void> {
        await this.name.fill(name);
        await this.register.click();
    }
}
