import { expect, test } from '../../fixtures';

/**
 * El visor no tiene roles: quien decide si existe es el toggle `DOCS_ENABLED`
 * (`.env.e2e` lo deja encendido, y el caso apagado lo cubre
 * `app/Modules/Docs/Tests/Feature/DocsToggleTest.php`, que es donde se puede
 * rearrancar la aplicación con otro entorno).
 *
 * Lo que sí hay que proteger es lo que un visor de archivos siempre tiene que
 * proteger: la carpeta que sirve. Se comprueba con peticiones crudas
 * (`request`, no `page.goto`) porque el parser de URL del navegador normaliza
 * los `..` antes de mandar nada y la petición no llegaría tal cual al servidor.
 */
test.describe('Docs · acceso', () => {
    test('el visor es público: un invitado lo lee', async ({ request }) => {
        const response = await request.get('/docs');

        expect(response.status()).toBe(200);
    });

    test('no se puede salir de docs/ con una ruta escapada', async ({ request }) => {
        for (const url of ['/docs/..%2F.env', '/docs/architecture%2F..%2F..%2F.env']) {
            const response = await request.get(url);

            expect(response.status(), url).toBe(404);
        }
    });

    test('un documento que no existe es un 404', async ({ request }) => {
        const response = await request.get('/docs/no-existe');

        expect(response.status()).toBe(404);
    });

    test('un archivo del repositorio fuera de docs/ no se sirve', async ({ request }) => {
        const response = await request.get('/docs/CHANGELOG');

        expect(response.status()).toBe(404);
    });
});
