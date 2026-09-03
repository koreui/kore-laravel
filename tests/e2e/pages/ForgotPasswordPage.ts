import type { Locator, Page } from '@playwright/test';

/** `/forgot-password` — vista de Fortify (`auth::pages.forgot-password`). */
export class ForgotPasswordPage {
    readonly email: Locator;

    readonly submit: Locator;

    /** `<x-kore::alert type="success" live="polite">` → `role="status"`. */
    readonly statusAlert: Locator;

    readonly errorAlert: Locator;

    readonly backToLoginLink: Locator;

    constructor(private readonly page: Page) {
        this.email = page.getByLabel('Correo electrónico');
        this.submit = page.getByRole('button', { name: 'Enviar enlace' });
        this.statusAlert = page.getByRole('status');
        this.errorAlert = page.getByRole('alert');
        this.backToLoginLink = page.getByRole('link', { name: 'Volver al login' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/forgot-password');
    }

    async request(email: string): Promise<void> {
        await this.email.fill(email);
        await this.submit.click();
    }
}
