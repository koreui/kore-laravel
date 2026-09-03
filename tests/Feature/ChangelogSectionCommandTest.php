<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| kore:changelog:section
|--------------------------------------------------------------------------
|
| R42 · toda release tiene su entrada en el CHANGELOG. El comando es lo que
| convierte esa regla en un verificador: `.github/workflows/release.yml` le pide
| el cuerpo del release y, si no hay sección, el release no se publica.
|
| Como el resto de comandos de Core, se prueba con `--root` sobre un árbol
| temporal.
|
*/

/** Un CHANGELOG de juguete con tres secciones y el formato Keep a Changelog. */
const CHANGELOG_FIXTURE = <<<'MD'
    # Changelog

    ## [Unreleased]

    ## [1.4.0] - 2026-09-03

    ### Añadido

    - Una cosa.
    - Otra cosa.

    ### Migración desde 1.3.0

    1. Copia el archivo.

    ## [1.3.0] - 2026-09-01

    ### Añadido

    - Lo de la release anterior.
    MD;

/**
 * Crea un árbol temporal con el CHANGELOG que se le pase.
 */
function changelogFixture(?string $contents = CHANGELOG_FIXTURE): string
{
    $root = storage_path('framework/testing/changelog-section/'.uniqid('case_', true));

    File::ensureDirectoryExists($root);

    if ($contents !== null) {
        File::put($root.'/CHANGELOG.md', $contents."\n");
    }

    return $root;
}

/**
 * @return array{0: int, 1: string}
 */
function changelogSection(string $root, string $version): array
{
    $exit = Artisan::call('kore:changelog:section', ['version' => $version, '--root' => $root]);

    return [$exit, Artisan::output()];
}

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/changelog-section'));
});

it('imprime la sección de la versión sin su encabezado', function (): void {
    [$exit, $output] = changelogSection(changelogFixture(), 'v1.4.0');

    expect($exit)->toBe(0)
        ->and($output)->toContain('- Una cosa.')
        ->and($output)->toContain('### Migración desde 1.3.0')
        // El encabezado no entra: el nombre del release ya es el tag.
        ->and($output)->not->toContain('## [1.4.0]');
});

it('para en la sección siguiente', function (): void {
    [, $output] = changelogSection(changelogFixture(), 'v1.4.0');

    expect($output)->not->toContain('Lo de la release anterior.')
        ->and($output)->not->toContain('## [1.3.0]');
});

it('acepta el tag con y sin la v inicial', function (string $version): void {
    [$exit, $output] = changelogSection(changelogFixture(), $version);

    expect($exit)->toBe(0)->and($output)->toContain('- Una cosa.');
})->with(['v1.4.0', '1.4.0', 'V1.4.0']);

it('falla cuando la versión no tiene sección', function (): void {
    [$exit, $output] = changelogSection(changelogFixture(), 'v9.9.9');

    expect($exit)->toBe(1)
        ->and($output)->toContain('## [9.9.9]')
        ->and($output)->toContain('R42');
});

it('falla cuando la sección existe pero está vacía', function (): void {
    // `[Unreleased]` no lleva nada: publicar un release con cuerpo vacío es
    // exactamente lo que R42 viene a evitar.
    [$exit, $output] = changelogSection(changelogFixture(), 'Unreleased');

    expect($exit)->toBe(1)->and($output)->toContain('con contenido');
});

it('falla cuando no hay CHANGELOG.md', function (): void {
    [$exit, $output] = changelogSection(changelogFixture(contents: null), 'v1.4.0');

    expect($exit)->toBe(1)->and($output)->toContain('No existe CHANGELOG.md');
});

it('encuentra la sección de la última release del repositorio real', function (): void {
    [$exit, $output] = changelogSection(base_path(), 'v1.3.0');

    expect($exit)->toBe(0)->and(trim($output))->not->toBe('');
});
