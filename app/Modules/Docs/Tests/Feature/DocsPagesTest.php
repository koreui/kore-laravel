<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| El visor de /docs, encendido
|--------------------------------------------------------------------------
|
| Todo lo de aquí corre dentro de `withEnvironment(['DOCS_ENABLED' => 'true'])`
| porque la suite arranca con el toggle apagado (ver DocsToggleTest).
|
| Los documentos que se piden son reales (`docs/README.md`,
| `docs/architecture/rules.md`, `docs/architecture/overview.md`): si alguien
| los renombra sin tocar el índice, R40 lo dice antes que esto, y si los borra,
| esto también.
|
*/

/**
 * Arranca la aplicación con el visor encendido y ejecuta el callback.
 */
function withDocsEnabled(Closure $callback): void
{
    withEnvironment(['DOCS_ENABLED' => 'true'], $callback);
}

it('renders the master index at /docs', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Documentación de kore-laravel')
            ->assertSee('href="/docs/architecture/rules"', escape: false);
    });
});

it('renders a document with its markdown converted to html', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/docs/architecture/rules')
            ->assertOk()
            // El título sale del primer `# ` y lo pinta la plantilla como <h1>.
            ->assertSee('Reglas de arquitectura')
            ->assertSee('R1')
            // GFM: las tablas del catálogo tienen que llegar como tabla, no como
            // una fila de barras verticales.
            ->assertSee('<table>', escape: false)
            ->assertSee('<th>', escape: false)
            // Los encabezados llevan ancla, que es lo que hace que los enlaces
            // `otro.md#seccion` de los docs sigan funcionando aquí.
            ->assertSee('<h2 id="', escape: false);
    });
});

it('rewrites the relative links between documents and leaves the absolute ones alone', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/docs/architecture/overview')
            ->assertOk()
            // `](rules.md)` → mismo directorio.
            ->assertSee('href="/docs/architecture/rules"', escape: false)
            // `](../quality/pipeline.md)` → sube un nivel.
            ->assertSee('href="/docs/quality/pipeline"', escape: false)
            // Un `https://` no se toca.
            ->assertSee('href="https://packagist.org/packages/kore-ui/kore-ui"', escape: false);
    });
});

it('sends the links outside docs/ to github', function (): void {
    withDocsEnabled(function (): void {
        // `docs/README.md` enlaza `](../CHANGELOG.md)`, que está fuera de docs/
        // y por tanto no se puede servir: se manda al repositorio.
        $this->get('/docs')
            ->assertOk()
            ->assertSee('href="https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md"', escape: false);
    });
});

it('offers a link to the same document on github', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/docs/architecture/rules')
            ->assertOk()
            ->assertSee('https://github.com/koreui/kore-laravel/blob/main/docs/architecture/rules.md');
    });
});

it('answers 404 for a document that does not exist', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/docs/no-existe')->assertNotFound();
        $this->get('/docs/architecture/no-existe')->assertNotFound();
    });
});

/*
 * El agujero clásico de un visor de archivos: salirse de la carpeta que sirve.
 * Nunca 200 (serviría el `.env`) y nunca 500 (un error de PHP contando que el
 * archivo existe también es información).
 */
it('refuses to escape the docs folder', function (string $url): void {
    withDocsEnabled(function () use ($url): void {
        $this->get($url)->assertNotFound();
    });
})->with([
    '/docs/../.env',
    '/docs/..%2F.env',
    '/docs/../../.env',
    '/docs/%2e%2e/.env',
    '/docs/architecture/../../.env',
]);

it('does not serve a file outside the docs folder even by name', function (): void {
    withDocsEnabled(function (): void {
        // `README.md` existe en la raíz del repositorio, no en docs/.
        $this->get('/docs/CHANGELOG')->assertNotFound();
    });
});

/*
 * La otra cara de `tests/Feature/PublicPagesTest.php`: con el toggle apagado la
 * landing enlaza a GitHub; con él encendido, al visor.
 */
it('links the landing page to the local viewer when the toggle is on', function (): void {
    withDocsEnabled(function (): void {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="/docs"', escape: false)
            ->assertDontSee('https://github.com/koreui/kore-laravel/tree/main/docs');
    });
});
