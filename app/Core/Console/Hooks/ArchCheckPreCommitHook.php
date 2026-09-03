<?php

declare(strict_types=1);

namespace App\Core\Console\Hooks;

use Closure;
use Igorsgm\GitHooks\Contracts\PreCommitHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\ChangedFile;
use Igorsgm\GitHooks\Git\ChangedFiles;
use Illuminate\Console\Command;

/**
 * Capa 1 de verificación: `kore:arch:check` sobre los archivos staged.
 *
 * Presupuesto: ~2 s. Todo lo que tarde más se sube a pre-push. Un pre-commit
 * lento se acaba saltando con `--no-verify`, y entonces no verifica nada.
 *
 * Sólo se le pasan los archivos del commit, así que el comando corre sus checks
 * contra ese subconjunto (ver la opción `--files`). Los checks que necesitan
 * mirar todo el repositorio para no dar falsos positivos —el de toggles— lo
 * hacen igualmente.
 *
 * Ver docs/architecture/rules.md §Capas de verificación.
 */
final class ArchCheckPreCommitHook implements PreCommitHook
{
    public Command $command;

    public function getName(): string
    {
        return 'Kore arch check';
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    public function handle(ChangedFiles $files, Closure $next): mixed
    {
        $paths = $files->getAddedToCommit()
            ->map(fn (ChangedFile $file): string => $file->getFilePath())
            ->filter(fn (string $path): bool => $path !== '' && is_file(base_path($path)))
            ->unique()
            ->values()
            ->all();

        if ($paths === []) {
            return $next($files);
        }

        $exitCode = $this->command->call('kore:arch:check', [
            '--files' => implode(',', $paths),
        ]);

        if ($exitCode !== Command::SUCCESS) {
            throw new HookFailException;
        }

        return $next($files);
    }
}
