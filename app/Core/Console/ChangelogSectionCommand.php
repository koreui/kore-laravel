<?php

declare(strict_types=1);

namespace App\Core\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Imprime la sección del `CHANGELOG.md` que corresponde a una versión (R42).
 *
 * Lo usa `.github/workflows/release.yml`: al empujar un tag `vX.Y.Z`, el
 * workflow pide aquí el cuerpo de la release. Si la sección no existe, el
 * comando devuelve exit 1 y el release **no se publica** — que es la forma de
 * que R42 («toda release tiene su entrada en el CHANGELOG») deje de depender de
 * que alguien se acuerde en el review.
 *
 * No se usa release-please ni ningún generador desde los subjects de los
 * commits a propósito: el CHANGELOG de este boilerplate está escrito a mano, en
 * español y con nota de migración, porque es la API de actualización de los
 * proyectos derivados. Un generador lo reescribiría en inglés y sin ella.
 *
 * Uso:
 *   php artisan kore:changelog:section v1.4.0
 *   php artisan kore:changelog:section 1.4.0 --root=/otro/repo
 */
#[Description('Imprime la sección del CHANGELOG.md de una versión, o falla si no existe (R42)')]
#[Signature('kore:changelog:section
        {version : La versión o el tag, con o sin la v inicial (v1.4.0 o 1.4.0).}
        {--root= : Raíz del proyecto. Por defecto la de la aplicación; los tests la usan para apuntar a un árbol temporal.}')]
final class ChangelogSectionCommand extends Command
{
    public function handle(): int
    {
        $root = $this->option('root');
        $root = is_string($root) && $root !== '' ? rtrim($root, '/') : base_path();

        $changelog = $root.'/CHANGELOG.md';

        if (! is_file($changelog)) {
            $this->components->error('No existe CHANGELOG.md en '.$root.'.');

            return self::FAILURE;
        }

        $version = $this->version();
        $section = $this->section($changelog, $version);

        if ($section === null) {
            $this->components->error(
                "El CHANGELOG.md no tiene una sección «## [{$version}]» con contenido: toda release lleva la suya (R42)."
            );

            return self::FAILURE;
        }

        $this->output->writeln($section);

        return self::SUCCESS;
    }

    /**
     * `v1.4.0` y `1.4.0` son la misma versión; en el CHANGELOG va sin la `v`.
     */
    private function version(): string
    {
        $version = $this->argument('version');
        $version = is_string($version) ? trim($version) : '';

        return ltrim($version, 'vV');
    }

    /**
     * Cuerpo de la sección `## [X.Y.Z]`, sin su encabezado y sin líneas en
     * blanco al principio ni al final. `null` si la sección no está o si está
     * vacía: una release sin nada escrito tampoco cumple R42.
     */
    private function section(string $changelog, string $version): ?string
    {
        $contents = file_get_contents($changelog);

        if ($contents === false || $version === '') {
            return null;
        }

        $collecting = false;
        $body = [];

        foreach (explode("\n", $contents) as $line) {
            $isHeading = preg_match('/^##\s+/', $line) === 1;

            if ($isHeading) {
                if ($collecting) {
                    break;
                }

                $collecting = preg_match('/^##\s+\[?'.preg_quote($version, '/').'\]?(\s|$)/', $line) === 1;

                continue;
            }

            if ($collecting) {
                $body[] = $line;
            }
        }

        if (! $collecting) {
            return null;
        }

        $section = trim(implode("\n", $body));

        return $section === '' ? null : $section;
    }
}
