import type { Locator, Page } from '@playwright/test';

/**
 * `/docs` y `/docs/{path}` — vistas `docs::index` y `docs::show`.
 *
 * El visor es público (no hay sesión ni roles: lo que decide si existe es el
 * toggle `DOCS_ENABLED`, que `.env.e2e` deja encendido) y no lleva Livewire,
 * así que no hace falta esperar a la hidratación.
 */
export class DocsPage {
    /** El `<h1>` de la página, que sale del primer `# ` del Markdown. */
    readonly title: Locator;

    /** El breadcrumb `Docs › architecture › rules` de una página de documento. */
    readonly breadcrumbs: Locator;

    /** El índice lateral con los `##` del documento. */
    readonly tableOfContents: Locator;

    readonly github: Locator;

    /** Las tablas del Markdown, que son la prueba de que se renderiza GFM. */
    readonly tables: Locator;

    constructor(private readonly page: Page) {
        this.title = page.getByRole('heading', { level: 1 });
        // El nombre accesible lo pone koreUi en el <nav> del componente.
        this.breadcrumbs = page.getByRole('navigation', { name: 'Ruta de navegación' });
        this.tableOfContents = page.getByRole('navigation', { name: 'En esta página' });
        this.github = page.getByRole('link', { name: 'Ver en GitHub' });
        // Las tablas no tienen nombre accesible (el Markdown no genera
        // <caption>), así que se localizan por rol de tabla.
        this.tables = page.getByRole('table');
    }

    /** El índice maestro (`docs/README.md`). */
    async goto(): Promise<void> {
        await this.page.goto('/docs');
    }

    /** Un documento concreto, por su ruta relativa a `docs/` y sin `.md`. */
    async gotoDocument(path: string): Promise<void> {
        await this.page.goto(`/docs/${path}`);
    }

    /** Un enlace del documento renderizado, por su texto. */
    link(name: string): Locator {
        return this.page.getByRole('link', { name, exact: true });
    }

    /** Un encabezado `##` del documento renderizado. */
    heading(name: string | RegExp): Locator {
        return this.page.getByRole('heading', { name, level: 2 });
    }
}
