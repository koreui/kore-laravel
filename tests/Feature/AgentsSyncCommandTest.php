<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| kore:agents:sync
|--------------------------------------------------------------------------
|
| R50 · AGENTS.md se genera desde CLAUDE.md. Igual que ArchCheckCommandTest,
| estos tests trabajan sobre un árbol temporal con `--root`: el comando nunca
| escribe en el repositorio real, que con `pest --parallel` estaría corriendo
| sus propios checks a la vez.
|
*/

/**
 * Crea un árbol temporal y devuelve su raíz.
 *
 * @param array<string, string> $files ruta relativa => contenido
 */
function agentsFixture(array $files): string
{
    $root = storage_path('framework/testing/agents-sync/'.uniqid('case_', true));

    File::ensureDirectoryExists($root);

    foreach ($files as $path => $contents) {
        $absolute = $root.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $contents);
    }

    return $root;
}

/**
 * Corre el comando contra un árbol temporal y devuelve [exitCode, salida].
 *
 * @return array{0: int, 1: string}
 */
function agentsSync(string $root, bool $check = false): array
{
    $options = ['--root' => $root];

    if ($check) {
        $options['--check'] = true;
    }

    $exit = Artisan::call('kore:agents:sync', $options);

    return [$exit, Artisan::output()];
}

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/agents-sync'));
});

it('genera AGENTS.md con la cabecera y CLAUDE.md íntegro debajo', function (): void {
    $root = agentsFixture(['CLAUDE.md' => "# kore-laravel\n\nReglas vivas.\n"]);

    [$exit, $output] = agentsSync($root);

    $generated = File::get($root.'/AGENTS.md');

    expect($exit)->toBe(0)
        ->and($output)->toContain('regenerado')
        ->and($generated)->toStartWith('<!--')
        ->and($generated)->toContain('php artisan kore:agents:sync')
        ->and($generated)->toContain('No edites este archivo')
        ->and($generated)->toEndWith("# kore-laravel\n\nReglas vivas.\n");
});

it('--check pasa cuando AGENTS.md está al día', function (): void {
    $root = agentsFixture(['CLAUDE.md' => "# kore-laravel\n"]);

    agentsSync($root);

    [$exit, $output] = agentsSync($root, check: true);

    expect($exit)->toBe(0)->and($output)->toContain('está al día');
});

it('--check falla al tocar CLAUDE.md y no reescribe AGENTS.md', function (): void {
    $root = agentsFixture(['CLAUDE.md' => "# kore-laravel\n"]);

    agentsSync($root);

    File::put($root.'/CLAUDE.md', "# kore-laravel\n\nUna regla nueva.\n");
    $before = File::get($root.'/AGENTS.md');

    [$exit, $output] = agentsSync($root, check: true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('no coincide')
        ->and($output)->toContain('kore:agents:sync')
        // Un `--check` que arreglara el archivo escondería el problema: lo que
        // corre en el pre-commit y en composer arch no escribe nunca.
        ->and(File::get($root.'/AGENTS.md'))->toBe($before);
});

it('regenera AGENTS.md cuando CLAUDE.md cambia', function (): void {
    $root = agentsFixture(['CLAUDE.md' => "# kore-laravel\n"]);

    agentsSync($root);
    File::put($root.'/CLAUDE.md', "# kore-laravel\n\nUna regla nueva.\n");
    agentsSync($root);

    [$exit] = agentsSync($root, check: true);

    expect($exit)->toBe(0)
        ->and(File::get($root.'/AGENTS.md'))->toContain('Una regla nueva.');
});

it('avisa sin escribir cuando ya estaba al día', function (): void {
    $root = agentsFixture(['CLAUDE.md' => "# kore-laravel\n"]);

    agentsSync($root);
    [$exit, $output] = agentsSync($root);

    expect($exit)->toBe(0)->and($output)->toContain('ya estaba al día');
});

it('falla cuando no hay CLAUDE.md desde el que generar', function (): void {
    $root = agentsFixture([]);

    [$exit, $output] = agentsSync($root);

    expect($exit)->toBe(1)
        ->and($output)->toContain('CLAUDE.md no existe')
        ->and(File::exists($root.'/AGENTS.md'))->toBeFalse();
});

it('el repositorio real tiene AGENTS.md al día', function (): void {
    [$exit] = agentsSync(base_path(), check: true);

    expect($exit)->toBe(0);
});
