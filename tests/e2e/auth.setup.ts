import { test as setup, expect } from '@playwright/test';

import { LoginPage } from './pages/LoginPage';
import { ROLES, SEEDED_USERS, storageStateFor } from './support/users';

/**
 * Proyecto `setup`: inicia sesión por la UI real (POST a `/login` de Fortify)
 * con cada cuenta sembrada y guarda la cookie de sesión en
 * `tests/e2e/.auth/<rol>.json`.
 *
 * Los specs se lo comen con `test.use({ storageState: asRole('editor') })`, y
 * así ninguno paga el login otra vez. El único spec que sí ejercita el
 * formulario es `specs/auth/login.spec.ts`.
 *
 * El rate limiter de Fortify va por `email|ip`, así que las cuatro sesiones en
 * paralelo no se estorban.
 */
for (const role of ROLES) {
    setup(`autentica a ${role}`, async ({ page }) => {
        const user = SEEDED_USERS[role];
        const login = new LoginPage(page);

        await login.goto();
        await login.login(user.email, user.password);

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { name: new RegExp(user.name) })).toBeVisible();

        await page.context().storageState({ path: storageStateFor(role) });
    });
}
