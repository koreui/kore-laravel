import { expect, type Page } from '@playwright/test';

import { UserFormPage } from '../pages/UserFormPage';
import { UsersIndexPage } from '../pages/UsersIndexPage';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from './data';

export type CreatedUser = {
    name: string;
    email: string;
    password: string;
    role: 'Administrador' | 'Usuario';
};

/**
 * Crea un usuario por la UI real (`/users/create`).
 *
 * `page` tiene que venir de una sesión con `users.create`. Los usuarios que
 * nacen por aquí quedan con `email_verified_at` puesto (lo hace
 * `UserCreateAction`), así que sirven para probar login y magic link.
 */
export async function createUserViaUi(
    page: Page,
    overrides: Partial<CreatedUser> = {},
): Promise<CreatedUser> {
    const user: CreatedUser = {
        name: overrides.name ?? uniqueName(),
        email: overrides.email ?? uniqueEmail(),
        password: overrides.password ?? STRONG_PASSWORD,
        role: overrides.role ?? 'Usuario',
    };

    const form = new UserFormPage(page);
    const index = new UsersIndexPage(page);

    await form.gotoCreate();
    await expect(form.cardTitle).toBeVisible();

    // El rol va con `wire:model.live`: se elige primero y `selectRole()`
    // espera a que vuelva el round-trip. El resto de campos son diferidos y
    // viajan con el envío.
    await form.selectRole(user.role);

    await form.fill({ name: user.name, email: user.email, password: user.password });
    await form.save();

    await expect(page).toHaveURL(/\/users$/);
    await expect(index.row(user.email)).toBeVisible();

    return user;
}
