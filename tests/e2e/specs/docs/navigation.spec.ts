import { expect, test } from '../../fixtures';
import { DocsPage } from '../../pages/DocsPage';

/**
 * Happy path: desde el índice se llega a un documento, se lee entero (con sus
 * tablas) y se vuelve por el breadcrumb. Es el caso de uso central del módulo:
 * leer la documentación del repositorio sin salir de la aplicación.
 */
test.describe('Docs · navegación', () => {
    test('desde el índice se llega al catálogo de reglas', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.goto();
        // `first()`: el README enlaza el catálogo dos veces (índice y «Cómo se usa»).
        await docs.link('architecture/rules.md').first().click();

        await expect(page).toHaveURL(/\/docs\/architecture\/rules$/);
        await expect(docs.title).toHaveText(/^Reglas de arquitectura/);

        // El catálogo es sobre todo tablas: si no se renderizara GFM, se verían
        // filas de barras verticales en vez de una tabla.
        await expect(docs.tables.first()).toBeVisible();

        // Y los encabezados llevan ancla, que es lo que alimenta el índice lateral.
        await expect(docs.tableOfContents).toBeVisible();
    });

    test('el breadcrumb refleja la ruta y vuelve al índice', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.gotoDocument('architecture/rules');

        await expect(docs.breadcrumbs).toContainText('Docs');
        await expect(docs.breadcrumbs).toContainText('architecture');
        await expect(docs.breadcrumbs).toContainText('rules');

        await docs.breadcrumbs.getByRole('link', { name: 'Docs' }).click();

        await expect(page).toHaveURL(/\/docs$/);
        await expect(docs.title).toHaveText('Documentación de kore-laravel');
    });

    test('el índice lateral salta a la sección del documento', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.gotoDocument('architecture/rules');

        const first = docs.tableOfContents.getByRole('link').first();
        const label = (await first.textContent())?.trim() ?? '';

        await first.click();

        await expect(page).toHaveURL(/#/);
        await expect(docs.heading(label)).toBeVisible();
    });

    test('los enlaces entre documentos siguen funcionando dentro del visor', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.gotoDocument('architecture/overview');

        // `](rules.md)` en el Markdown, resuelto contra la carpeta del documento.
        await docs.link('rules.md').first().click();

        await expect(page).toHaveURL(/\/docs\/architecture\/rules$/);
        await expect(docs.title).toHaveText(/^Reglas de arquitectura/);
    });

    test('cada documento ofrece su original en GitHub', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.gotoDocument('quality/e2e');

        await expect(docs.github).toHaveAttribute(
            'href',
            'https://github.com/koreui/kore-laravel/blob/main/docs/quality/e2e.md',
        );
    });
});
