import type { Locator, Page } from '@playwright/test';

/** `/login` — vista de Fortify (`auth::pages.login`). */
export class LoginPage {
    readonly email: Locator;

    readonly password: Locator;

    readonly remember: Locator;

    readonly submit: Locator;

    readonly errorAlert: Locator;

    readonly statusAlert: Locator;

    readonly registerLink: Locator;

    readonly forgotPasswordLink: Locator;

    readonly magicLinkLink: Locator;

    constructor(private readonly page: Page) {
        this.email = page.getByLabel('Correo electrónico');
        // CANDIDATO A MEJORA DE ACCESIBILIDAD: en login.blade.php el campo de
        // contraseña se pinta sin `:label`, así que <x-kore::password> no
        // emite <label for>. La etiqueta visible es un <span> hermano y el
        // input se queda sin nombre accesible → no hay getByLabel posible.
        this.password = page.locator('#kore-password');
        this.remember = page.getByLabel('Mantener sesión iniciada');
        this.submit = page.getByRole('button', { name: 'Entrar' });
        // Un login fallido pinta DOS role="alert": la alerta general de la
        // tarjeta y el error del campo email.
        this.errorAlert = page.getByRole('alert').first();
        this.statusAlert = page.getByRole('status');
        this.registerLink = page.getByRole('link', { name: 'Crear cuenta' });
        this.forgotPasswordLink = page.getByRole('link', { name: '¿Olvidaste tu contraseña?' });
        this.magicLinkLink = page.getByRole('link', { name: 'Código por email' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/login');
    }

    async login(email: string, password: string): Promise<void> {
        await this.email.fill(email);
        await this.password.fill(password);
        await this.submit.click();
    }
}
