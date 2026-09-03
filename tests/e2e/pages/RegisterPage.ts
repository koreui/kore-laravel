import type { Locator, Page } from '@playwright/test';

/** `/register` — vista de Fortify (`auth::pages.register`). */
export class RegisterPage {
    readonly name: Locator;

    readonly email: Locator;

    readonly password: Locator;

    readonly passwordConfirmation: Locator;

    readonly submit: Locator;

    readonly errorAlert: Locator;

    readonly loginLink: Locator;

    constructor(private readonly page: Page) {
        this.name = page.getByLabel('Nombre completo');
        this.email = page.getByLabel('Correo electrónico');
        // `exact` con el asterisco incluido: `required` lo mete DENTRO del
        // <label>, así que forma parte del texto. Y hace falta ser exacto
        // porque getByLabel también mira `aria-label`, y el ojo de
        // <x-kore::password> se llama "Mostrar la contraseña".
        this.password = page.getByLabel('Contraseña *', { exact: true });
        this.passwordConfirmation = page.getByLabel('Confirmar contraseña *', { exact: true });
        this.submit = page.getByRole('button', { name: 'Crear cuenta' });
        // Fortify repinta el error dos veces: la alerta general y el mensaje
        // del campo. Ambas son role="alert".
        this.errorAlert = page.getByRole('alert').first();
        this.loginLink = page.getByRole('link', { name: 'Inicia sesión' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/register');
    }

    async register(input: {
        name: string;
        email: string;
        password: string;
        passwordConfirmation?: string;
    }): Promise<void> {
        await this.name.fill(input.name);
        await this.email.fill(input.email);
        await this.password.fill(input.password);
        await this.passwordConfirmation.fill(input.passwordConfirmation ?? input.password);
        await this.submit.click();
    }
}
