import { expect, test } from '../../fixtures';
import { uniqueEmail, uniqueName, STRONG_PASSWORD } from '../../support/data';

/**
 * El harness probándose a sí mismo.
 *
 * Va antes que cualquier spec que lo use, a propósito: si el atrezzo está
 * roto, lo que venga detrás fallaría por el montaje y no por lo que quiere
 * probar. Media hora buscando un bug que no existe.
 *
 * ## Se salta solo si el módulo no está
 *
 * En el boilerplate `app/Modules/E2E` está presente y estos tests corren. Si
 * un derivado no lo copió, `/__e2e__/ping` responde 404, este archivo se salta
 * entero y la suite sigue verde — que es justo lo que tiene que pasar: **ningún otro spec depende todavía del harness**, así
 * que su ausencia no puede costar un rojo.
 *
 * Con el módulo presente, estos tests corren. Si además
 * fallan, el problema está en el harness y el mensaje lo dirá con `[harness]`
 * delante.
 */
test.describe('Harness · el andamiaje de la suite', () => {
    test.beforeEach(async ({ harness }) => {
        test.skip(
            !(await harness.estaDisponible()),
            'app/Modules/E2E todavía no está en este árbol (/__e2e__/ping responde 404).',
        );
    });

    test('apunta a un entorno de pruebas y no al de desarrollo', async ({ harness }) => {
        const info = await harness.ping();

        expect(info.ok).toBe(true);
        expect(info.environment).toBe('e2e');
        // El candado que de verdad importa: `migrate:fresh` sobre la base
        // equivocada se lleva por delante el trabajo de alguien.
        expect(info.database).toMatch(/e2e|test/i);
        expect(info.users).toBeGreaterThan(0);
    });

    test('crea un usuario, entra con él y lo borra', async ({ harness, page }) => {
        const email = uniqueEmail('harness');

        // --- Montaje ---------------------------------------------------------
        const creado = await harness.createUser({
            role: 'Usuario',
            email,
            name: uniqueName('Harness'),
            password: STRONG_PASSWORD,
            permissions: ['users.view'],
        });

        expect(creado.email).toBe(email);
        expect(creado.roles).toContain('Usuario');
        expect(creado.permissions).toContain('users.view');

        // --- La sesión que abre el harness es una sesión de verdad ------------
        await harness.loginAs(email);

        const dashboard = await page.goto('/dashboard');
        expect(dashboard?.status(), 'la sesión del harness no llegó al navegador').toBe(200);

        // Y los permisos directos que se le pidieron son los que tiene.
        const users = await page.request.get('/users', {
            maxRedirects: 0,
            failOnStatusCode: false,
        });
        expect(users.status(), 'el permiso users.view no se aplicó').toBe(200);

        // --- Limpieza --------------------------------------------------------
        await harness.logout();
        await harness.deleteUser(email);

        // Borrado de verdad: el usuario ya no puede entrar.
        await expect(harness.loginAs(email)).rejects.toThrow(/harness/i);
    });
});
