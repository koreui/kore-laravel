<?php

declare(strict_types=1);

use App\Modules\Docs\Support\DocLinkExtension;
use App\Modules\Docs\Support\MarkdownRenderer;

/*
|--------------------------------------------------------------------------
| MarkdownRenderer · reescritura de enlaces y estructura del documento
|--------------------------------------------------------------------------
|
| Todo con cadenas: ni HTTP, ni base de datos, ni archivos del repositorio. Lo
| que se prueba aquí es la traducción «enlace escrito para GitHub» → «enlace que
| funciona dentro del visor», que es la única parte del módulo con reglas
| propias.
|
*/

/**
 * El destino de un enlace, visto desde un documento concreto.
 */
function rewriteFrom(string $document, string $target): string
{
    return (new DocLinkExtension($document))->rewrite($target);
}

it('rewrites a link to a sibling document', function (): void {
    expect(rewriteFrom('architecture/overview', 'rules.md'))->toBe('/docs/architecture/rules');
});

it('rewrites a link that climbs to another folder', function (): void {
    expect(rewriteFrom('modules/users', '../architecture/rules.md'))->toBe('/docs/architecture/rules');
});

it('keeps the anchor when it rewrites', function (): void {
    expect(rewriteFrom('architecture/overview', 'rules.md#r11'))->toBe('/docs/architecture/rules#r11')
        ->and(rewriteFrom('modules/auth', '../architecture/authorization.md#anti-escalada'))
        ->toBe('/docs/architecture/authorization#anti-escalada');
});

it('points the master index at /docs and not at /docs/README', function (): void {
    expect(rewriteFrom('architecture/rules', '../README.md'))->toBe('/docs');
});

it('sends a document outside docs/ to github', function (): void {
    expect(rewriteFrom('README', '../CHANGELOG.md'))
        ->toBe('https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md')
        ->and(rewriteFrom('architecture/rules', '../../CHANGELOG.md'))
        ->toBe('https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md');
});

it('leaves untouched everything that is not a relative link between documents', function (string $target): void {
    expect(rewriteFrom('architecture/rules', $target))->toBe($target);
})->with([
    'https://laravel.com/docs',
    'http://example.test/algo.md',
    '//example.test/algo.md',
    'mailto:hola@example.test',
    '#una-ancla-de-esta-misma-pagina',
    '/users',
    // Se sale del repositorio: no hay nada que reescribir.
    '../../../fuera.md',
]);

it('builds the github url of a file in the repository', function (): void {
    expect(MarkdownRenderer::githubUrl('docs/architecture/rules.md'))
        ->toBe('https://github.com/koreui/kore-laravel/blob/main/docs/architecture/rules.md');
});

it('slugs a heading the way github does', function (string $text, string $slug): void {
    expect(DocLinkExtension::slug($text))->toBe($slug);
})->with([
    ['Autorización', 'autorización'],
    ['Magic links (OTP)', 'magic-links-otp'],
    ['Backups (spatie/laravel-backup)', 'backups-spatielaravel-backup'],
    ['R11 · Un toggle sólo existe si alguien lo lee', 'r11--un-toggle-sólo-existe-si-alguien-lo-lee'],
]);

/*
|--------------------------------------------------------------------------
| El documento completo
|--------------------------------------------------------------------------
*/

it('takes the title from the first heading and drops it from the body', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# Reglas de arquitectura\n\nUn párrafo.\n",
        'architecture/rules',
    );

    expect($document->title)->toBe('Reglas de arquitectura')
        ->and($document->path)->toBe('architecture/rules')
        ->and($document->html)->toContain('<p>Un párrafo.</p>')
        // El <h1> lo pinta la plantilla; dentro de la prosa sobraría.
        ->and($document->html)->not->toContain('<h1');
});

it('falls back to the path when the document has no title', function (): void {
    $document = (new MarkdownRenderer)->render("Sin título.\n", 'guides/crud');

    expect($document->title)->toBe('guides/crud')
        ->and($document->html)->toContain('Sin título.');
});

it('collects the level two headings with the same ids it renders', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# Título\n\n## Primera\n\ntexto\n\n### Ignorada\n\n## Segunda\n",
        'guides/crud',
    );

    expect($document->headings)->toBe([
        ['id' => 'primera', 'text' => 'Primera'],
        ['id' => 'segunda', 'text' => 'Segunda'],
    ])
        ->and($document->html)->toContain('<h2 id="primera">')
        ->and($document->html)->toContain('<h2 id="segunda">')
        ->and($document->html)->toContain('<h3 id="ignorada">');
});

it('renders github flavoured markdown: tables and code fences', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# T\n\n| Regla | Qué |\n|---|---|\n| R1 | una Action |\n\n```php\n\$x = 1;\n```\n",
        'architecture/rules',
    );

    expect($document->html)->toContain('<table>')
        ->toContain('<th>Regla</th>')
        ->toContain('<td>R1</td>')
        ->toContain('<pre><code class="language-php">');
});

it('rewrites the links of a rendered document', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# T\n\nVer [reglas](rules.md#r5) y [el changelog](../../CHANGELOG.md) y [laravel](https://laravel.com).\n",
        'architecture/overview',
    );

    expect($document->html)->toContain('href="/docs/architecture/rules#r5"')
        ->toContain('href="https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md"')
        ->toContain('href="https://laravel.com"');
});

/*
 * La razón de reescribir sobre el árbol parseado y no con una regex sobre el
 * texto: `docs/audit/…` cita literalmente un enlace dentro de un span de código.
 * Reescribirlo cambiaría lo que el documento dice.
 */
it('does not touch a link written inside code', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# T\n\nEl README enlaza `[koreUi](../koreUi)`.\n\n```md\n[reglas](rules.md)\n```\n",
        'audit/2026-09-02',
    );

    expect($document->html)->toContain('<code>[koreUi](../koreUi)</code>')
        ->toContain('[reglas](rules.md)')
        ->not->toContain('href="/docs');
});

/*
 * `html_input => 'strip'`. Los docs son del repositorio y son de fiar, pero la
 * plantilla los pinta con `{!! !!}` y ésta es la única frontera que hay.
 */
it('strips raw html instead of copying it to the output', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# T\n\n<script>alert(1)</script>\n\n<div onclick=\"robar()\">hola</div>\n",
        'guides/crud',
    );

    expect($document->html)->not->toContain('<script')
        ->not->toContain('onclick');
});

it('drops an unsafe link scheme', function (): void {
    $document = (new MarkdownRenderer)->render(
        "# T\n\n[pulsa](javascript:alert(1))\n",
        'guides/crud',
    );

    expect($document->html)->not->toContain('javascript:');
});
