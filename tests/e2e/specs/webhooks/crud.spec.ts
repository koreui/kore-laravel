import { expect, test } from '../../fixtures';
import { WebhooksPage } from '../../pages/WebhooksPage';
import { uniqueName } from '../../support/data';

/**
 * El camino feliz completo y la puerta cerrada.
 *
 * El alta comprueba la promesa que hace la pantalla —«el secreto se muestra una
 * sola vez»— de la única forma en que se puede comprobar: recargando. Si el
 * secreto siguiera saliendo en la segunda visita, la promesa sería mentira y
 * nadie se enteraría.
 *
 * ## Por qué el archivo va en serie
 *
 * El 403 se comprueba sobre `/webhooks/{uuid}`, que lleva parámetro y por eso
 * no cabe en `fixtures/access-map.ts` (un mapa de literales). Hace falta un
 * endpoint real, y crearlo pide el rol superadmin mientras que comprobarlo pide
 * el rol viewer: dos sesiones.
 *
 * En vez de las fixtures `asViewer` / `asSuperadmin` —que abren un contexto a
 * mano y, cuando al worker no le tocó la sesión que dejó el proyecto `setup`,
 * la rehacen por la UI— el archivo va en serie y cada describe declara su rol
 * con `test.use`. Es determinista y no depende de qué worker recoja el spec; el
 * precio es que la ruta del detalle viaja en una variable de módulo, y por eso
 * el segundo test se salta solo si el primero no llegó a crearlo.
 *
 * R39: el endpoint lo crea el propio test con un nombre único, porque la base
 * sólo se resetea en `globalSetup` y otros specs corren en paralelo.
 */
test.describe.configure({ mode: 'serial' });

/** Ruta del detalle del endpoint que crea el primer test, para el segundo. */
let rutaDetalle: string | null = null;

test.describe('Webhooks · CRUD', () => {
    test.use({ role: 'superadmin' });

    test('crea un endpoint, enseña el secreto una vez y aparece en la tabla', async ({ page }) => {
        const webhooks = new WebhooksPage(page);
        const name = uniqueName('Hook');

        await webhooks.gotoCreate();
        await webhooks.createEndpoint(name, 'https://example.test/hooks/kore');

        // Redirige al detalle, cuyo path lleva el uuid del endpoint.
        await expect(page).toHaveURL(/\/webhooks\/[0-9a-f-]{36}$/);
        rutaDetalle = new URL(page.url()).pathname;

        await expect(page.getByText('Cópialo ahora')).toBeVisible();

        const secreto = await page.getByLabel('Secreto', { exact: true }).inputValue();
        expect(secreto.length).toBeGreaterThan(30);

        // La promesa: una sola vez. En la recarga ya no está.
        await page.reload();
        await expect(page.getByText('Cópialo ahora')).toHaveCount(0);

        await webhooks.goto();
        await webhooks.focusOnRow(name);
    });
});

test.describe('Webhooks · acceso al detalle', () => {
    test.use({ role: 'viewer' });

    test('un perfil sin webhooks.manage se lleva un 403 en el detalle', async ({ page }) => {
        test.skip(rutaDetalle === null, 'el test que crea el endpoint no llegó a terminar');

        // `page.request` y no `goto`: lo que se comprueba es lo que devuelve el
        // servidor, y el guardia de errores cuenta como grave un 4xx cargado
        // con `goto` (ver access/rbac.spec.ts).
        const respuesta = await page.request.get(rutaDetalle as string, {
            maxRedirects: 0,
            failOnStatusCode: false,
        });

        expect(respuesta.status(), `viewer en ${rutaDetalle}`).toBe(403);
    });
});
