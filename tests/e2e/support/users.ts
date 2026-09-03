import path from 'node:path';

import { authStateDir } from './env';

/**
 * Cuentas que siembra `Database\Seeders\E2eSeeder`. Una por nivel de
 * autorización. Ningún spec las modifica: los datos que un test necesita
 * cambiar los crea el propio test con `uniqueEmail()`.
 */
export const E2E_PASSWORD = 'password';

export type Role = 'superadmin' | 'editor' | 'viewer' | 'member';

export type SeededUser = {
    readonly email: string;
    readonly name: string;
    readonly password: string;
};

export const SEEDED_USERS: Readonly<Record<Role, SeededUser>> = {
    /** Bypass total: Gate::before de AuthModuleServiceProvider. */
    superadmin: { email: 'superadmin@e2e.test', name: 'E2E Superadmin', password: E2E_PASSWORD },
    /** users.view + users.create + users.edit (sin users.delete). */
    editor: { email: 'editor@e2e.test', name: 'E2E Editor', password: E2E_PASSWORD },
    /** Sólo users.view. */
    viewer: { email: 'viewer@e2e.test', name: 'E2E Viewer', password: E2E_PASSWORD },
    /** Sin permisos del módulo Users: sólo dashboard. */
    member: { email: 'member@e2e.test', name: 'E2E Member', password: E2E_PASSWORD },
};

export const ROLES = Object.keys(SEEDED_USERS) as Role[];

/**
 * Archivo de `storageState` de un rol.
 *
 * Sin `worker` devuelve el que deja el proyecto `setup`; con `worker`, el de
 * ese worker de Playwright. Una sesión por worker, no una para todos: la
 * cookie de sesión es la MISMA para todos los tests que compartan archivo, y
 * con `fullyParallel` eso significa peticiones concurrentes sobre la misma
 * sesión de Laravel. El síntoma que lo destapó: los toast que viajan por
 * `flash` (`Toast::viaSession()`) se los comía la primera petición que
 * llegara, aunque fuese la de otro spec.
 */
export function storageStateFor(role: Role, worker?: number): string {
    const suffix = worker === undefined || worker === 0 ? '' : `-w${worker}`;

    return path.join(authStateDir, `${role}${suffix}.json`);
}
