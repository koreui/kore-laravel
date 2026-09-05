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
 * ## Por qué las URLs son `https://example.com/...`
 *
 * Porque `Webhooks\Rules\PublicHttpUrl` resuelve el host y rechaza lo que
 * apunte a la red interna o no resuelva a nada. `localhost` y los `.test` no
 * pasan; `example.com` es un dominio reservado por la IANA que sí resuelve a
 * una IP pública, así que es la URL ficticia que el formulario acepta.
 *
 * ## Por qué el archivo NO va en serie
 *
 * El 403 se comprueba sobre `/webhooks/{uuid}`, que lleva parámetro y por eso
 * no cabe en `fixtures/access-map.ts` (un mapa de literales). Hace falta un
 * endpoint real, y crearlo pide el rol superadmin mientras que comprobarlo pide
 * el rol viewer: dos sesiones en el mismo test. Eso lo resuelven las fixtures
 * `asSuperadmin` / `asViewer`, que abren su propio contexto con la sesión de su
 * rol, y así cada test es independiente: no hay variable de módulo, ni orden
 * obligatorio, ni un segundo test que se salte solo porque el primero falló.
 *
 * R39: cada test crea sus propios datos con un nombre único, porque la base
 * sólo se resetea en `globalSetup` y otros specs corren en paralelo.
 */

/** URL ficticia pero resoluble, distinta por endpoint para no confundir filas. */
function urlFicticia(nombre: string): string {
    return `https://example.com/hooks/${encodeURIComponent(nombre)}`;
}

test.describe('Webhooks · CRUD', () => {
    test.use({ role: 'superadmin' });

    test('crea un endpoint, enseña el secreto una vez y aparece en la tabla', async ({ page }) => {
        const webhooks = new WebhooksPage(page);
        const name = uniqueName('Hook');

        await webhooks.gotoCreate();
        await webhooks.createEndpoint(name, urlFicticia(name));

        // Redirige al detalle, cuyo path lleva el uuid del endpoint.
        await expect(page).toHaveURL(/\/webhooks\/[0-9a-f-]{36}$/);

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
    test('un perfil sin webhooks.manage se lleva un 403 en el detalle', async ({
        asSuperadmin,
        asViewer,
    }) => {
        // El endpoint lo crea este mismo test, con su superadmin y su nombre
        // único: nada que heredar de otro test ni que saltarse si aquél falló.
        const webhooks = new WebhooksPage(asSuperadmin);
        const name = uniqueName('Hook403');

        await webhooks.gotoCreate();
        await webhooks.createEndpoint(name, urlFicticia(name));
        await expect(asSuperadmin).toHaveURL(/\/webhooks\/[0-9a-f-]{36}$/);

        const rutaDetalle = new URL(asSuperadmin.url()).pathname;

        // `request` y no `goto`: lo que se comprueba es lo que devuelve el
        // servidor, y el guardia de errores cuenta como grave un 4xx cargado
        // con `goto` (ver access/rbac.spec.ts).
        const respuesta = await asViewer.request.get(rutaDetalle, {
            maxRedirects: 0,
            failOnStatusCode: false,
        });

        expect(respuesta.status(), `viewer en ${rutaDetalle}`).toBe(403);
    });
});
