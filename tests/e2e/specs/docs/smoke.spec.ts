import { expect, test } from '../../fixtures';
import { DocsPage } from '../../pages/DocsPage';

/**
 * Smoke del visor de documentación: la pantalla principal carga con su heading
 * y su título. Sin `test.use({ role })`: `/docs` es público, y que lo sea forma
 * parte de lo que se comprueba.
 */
test.describe('Docs · índice', () => {
    test('carga con su heading y su título', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.goto();

        await expect(page).toHaveTitle('Documentación de kore-laravel · kore-laravel');
        await expect(docs.title).toHaveText('Documentación de kore-laravel');
    });

    test('el índice es el README de docs/, con sus enlaces ya reescritos', async ({ page }) => {
        const docs = new DocsPage(page);

        await docs.goto();

        // El índice maestro enlaza cada doc; el visor traduce
        // `architecture/rules.md` a una ruta suya. `first()`: el README lo
        // enlaza dos veces, en el índice y en «Cómo se usa».
        await expect(docs.link('architecture/rules.md').first()).toHaveAttribute(
            'href',
            '/docs/architecture/rules',
        );

        // Lo que está fuera de docs/ no se puede servir: se manda al repositorio.
        await expect(docs.link('CHANGELOG.md')).toHaveAttribute(
            'href',
            'https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md',
        );
    });

    test('la landing enlaza al visor local cuando el toggle está encendido', async ({ page }) => {
        await page.goto('/');

        // Dos enlaces a la documentación en la landing (hero y pie) y uno en la
        // cabecera del layout público: los tres apuntan al visor.
        await expect(page.getByRole('link', { name: 'Docs', exact: true })).toHaveAttribute(
            'href',
            '/docs',
        );
        await expect(page.getByRole('link', { name: /^Ver docs/ })).toHaveAttribute('href', '/docs');
        await expect(page.getByRole('link', { name: 'Documentación' })).toHaveAttribute(
            'href',
            '/docs',
        );
    });
});
