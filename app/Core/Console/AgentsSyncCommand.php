<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Support\AgentsFile;
use Illuminate\Console\Command;

/**
 * Genera `AGENTS.md` desde `CLAUDE.md` (R50).
 *
 * Los dos archivos decían lo mismo desde la v1.0.0 y se copiaban a mano: dos
 * archivos «idénticos» mantenidos por disciplina se desincronizan el día que
 * alguien edita el que tiene abierto. Desde la v1.4.0 uno es el original y el
 * otro un artefacto, con una cabecera que lo dice.
 *
 * Uso:
 *   php artisan kore:agents:sync            # regenera AGENTS.md
 *   php artisan kore:agents:sync --check    # exit 1 si está desincronizado
 *
 * El `--check` es lo que corren `kore:arch:check --rule=R50` y el pre-commit:
 * un hook no escribe archivos, sólo dice que hay que regenerarlo.
 */
final class AgentsSyncCommand extends Command
{
    /** @var string */
    protected $signature = 'kore:agents:sync
        {--check : No escribe nada; devuelve exit 1 si AGENTS.md no coincide con lo que se generaría.}
        {--root= : Raíz del proyecto. Por defecto la de la aplicación; los tests la usan para apuntar a un árbol temporal.}';

    /** @var string */
    protected $description = 'Genera AGENTS.md desde CLAUDE.md (R50)';

    public function handle(): int
    {
        $root = $this->option('root');
        $root = is_string($root) && $root !== '' ? rtrim($root, '/') : base_path();

        $file = new AgentsFile($root);

        if (! $file->sourceExists()) {
            $this->components->error(AgentsFile::SOURCE.' no existe: no hay nada desde lo que generar '.AgentsFile::GENERATED.'.');

            return self::FAILURE;
        }

        if ($this->option('check') === true) {
            return $this->check($file);
        }

        if (! $file->write()) {
            $this->components->info(AgentsFile::GENERATED.' ya estaba al día.');

            return self::SUCCESS;
        }

        $this->components->info(AgentsFile::GENERATED.' regenerado desde '.AgentsFile::SOURCE.'.');

        return self::SUCCESS;
    }

    private function check(AgentsFile $file): int
    {
        if ($file->isInSync()) {
            $this->components->info(AgentsFile::GENERATED.' está al día con '.AgentsFile::SOURCE.'.');

            return self::SUCCESS;
        }

        $this->components->error(
            AgentsFile::GENERATED.' no coincide con '.AgentsFile::SOURCE
            .': corre `php artisan kore:agents:sync` y vuelve a añadir '.AgentsFile::GENERATED.' al commit.'
        );

        return self::FAILURE;
    }
}
