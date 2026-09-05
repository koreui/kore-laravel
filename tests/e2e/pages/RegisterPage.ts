import type { Locator, Page } from '@playwright/test';

/** `/register` — vista de Fortify (`auth::pages.register`). */
export class RegisterPage {
    readonly name: Locator;

    readonly email: Locator;

    readonly password: Locator;

    readonly passwordConfirmation: Locator;

    /**
     * Sólo existe con `AUTH_INVITATIONS`, que `.env.e2e` enciende. El
     * localizador se declara siempre; `register()` decide si lo rellena
     * mirando si está en la página.
     */
    readonly invitationCode: Locator;

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
        this.invitationCode = page.getByLabel('Código de invitación *', { exact: true });
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
        invitationCode?: string;
    }): Promise<void> {
        await this.name.fill(input.name);
        await this.email.fill(input.email);
        await this.password.fill(input.password);
        await this.passwordConfirmation.fill(input.passwordConfirmation ?? input.password);

        // El campo sólo está cuando el toggle lo pone. Se pregunta por el DOM y
        // no por una variable de entorno: el spec no tiene por qué saber cómo
        // está configurada la instalación contra la que corre.
        if (input.invitationCode !== undefined && (await this.invitationCode.count()) > 0) {
            await this.invitationCode.fill(input.invitationCode);
        }

        await this.submit.click();
    }
}
