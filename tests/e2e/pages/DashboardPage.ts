import type { Locator, Page } from '@playwright/test';

/** `/dashboard` — vista `auth::pages.dashboard` dentro del app shell de koreUi. */
export class DashboardPage {
    readonly pageTitle: Locator;

    readonly greeting: Locator;

    readonly usersSidebarLink: Locator;

    readonly manageUsersCard: Locator;

    readonly logout: Locator;

    constructor(private readonly page: Page) {
        this.pageTitle = page.getByRole('heading', { name: 'Dashboard', exact: true });
        this.greeting = page.getByRole('heading', { name: /^Hola,/ });
        // `exact`: sin él también casaría "Gestionar usuarios".
        this.usersSidebarLink = page.getByRole('link', { name: 'Usuarios', exact: true });
        this.manageUsersCard = page.getByRole('link', { name: /Gestionar usuarios/ });
        this.logout = page.getByRole('button', { name: 'Cerrar sesión' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/dashboard');
    }

    async logOut(): Promise<void> {
        await this.logout.click();
    }
}
