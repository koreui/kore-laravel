import { expect, SEEDED_USERS, test } from '../../fixtures';
import { DashboardPage } from '../../pages/DashboardPage';

test.describe('Dashboard · member', () => {
    test.use({ role: 'member' });

    test('ve el dashboard con su nombre', async ({ page }) => {
        const dashboard = new DashboardPage(page);

        await dashboard.goto();

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(dashboard.pageTitle).toBeVisible();
        await expect(dashboard.greeting).toContainText(SEEDED_USERS.member.name);
    });

    test('sin users.view no ve el acceso a Usuarios', async ({ page }) => {
        const dashboard = new DashboardPage(page);

        await dashboard.goto();
        await expect(dashboard.greeting).toBeVisible();

        // Tanto el item del sidebar como la tarjeta de acción rápida están
        // detrás de @can('users.view').
        await expect(dashboard.usersSidebarLink).toHaveCount(0);
        await expect(dashboard.manageUsersCard).toHaveCount(0);
    });
});

test.describe('Dashboard · viewer', () => {
    test.use({ role: 'viewer' });

    test('con users.view sí ve el acceso a Usuarios', async ({ page }) => {
        const dashboard = new DashboardPage(page);

        await dashboard.goto();

        await expect(dashboard.greeting).toContainText(SEEDED_USERS.viewer.name);
        await expect(dashboard.manageUsersCard).toBeVisible();

        await dashboard.usersSidebarLink.click();
        await expect(page).toHaveURL(/\/users$/);
    });
});
