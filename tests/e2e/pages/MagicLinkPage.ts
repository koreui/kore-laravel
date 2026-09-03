import type { Locator, Page } from '@playwright/test';

import { waitForLivewireReady } from '../support/livewire';

/** `/magic-link` — componente Livewire `App\Modules\Auth\Http\Livewire\MagicLink`. */
export class MagicLinkPage {
    readonly email: Locator;

    readonly sendCode: Locator;

    readonly verify: Locator;

    readonly changeEmail: Locator;

    readonly errorAlert: Locator;

    readonly backToLoginLink: Locator;

    constructor(private readonly page: Page) {
        this.email = page.getByLabel('Correo electrónico');
        this.sendCode = page.getByRole('button', { name: 'Enviar código' });
        this.verify = page.getByRole('button', { name: 'Verificar y entrar' });
        this.changeEmail = page.getByRole('button', { name: 'Cambiar correo' });
        this.errorAlert = page.getByRole('alert');
        this.backToLoginLink = page.getByRole('link', { name: 'Volver al login normal' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/magic-link');
        await waitForLivewireReady(this.page);
    }

    /** Una de las seis casillas de `<x-kore::input-otp>` (`aria-label="Dígito N"`). */
    digit(position: number): Locator {
        return this.page.getByLabel(`Dígito ${position}`);
    }

    async requestCode(email: string): Promise<void> {
        await this.email.fill(email);
        await this.sendCode.click();
    }

    /**
     * Escribe el código casilla a casilla. El componente Alpine de koreUi
     * mueve el foco solo, pero localizamos cada input por su aria-label para
     * no depender de dónde acabó el foco.
     */
    async fillCode(code: string): Promise<void> {
        const digits = code.split('');

        for (let index = 0; index < digits.length; index++) {
            await this.digit(index + 1).fill(digits[index]);
        }
    }

    async submitCode(code: string): Promise<void> {
        await this.fillCode(code);
        await this.verify.click();
    }
}
