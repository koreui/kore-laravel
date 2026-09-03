<?php

declare(strict_types=1);

namespace App\Core\Console\Hooks;

use Closure;
use Igorsgm\GitHooks\Contracts\MessageHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\CommitMessage;
use Illuminate\Console\Command;

/**
 * Capa 0 de verificación: el asunto del commit sigue Conventional Commits (R43).
 *
 * Git invoca `commit-msg` con la ruta del archivo temporal donde está el
 * mensaje; el paquete lo lee y nos lo entrega ya envuelto en un
 * `Igorsgm\GitHooks\Git\CommitMessage`. Aquí sólo se mira la **primera línea
 * útil** —la primera que no es un comentario de git ni está en blanco—, porque
 * es la que acaba en el `git log --oneline` y la que un generador de CHANGELOG
 * sabría leer.
 *
 * Cuesta milisegundos: no lanza procesos ni toca la base de datos.
 *
 * Ver docs/architecture/rules.md §Capas de verificación.
 */
final class ConventionalCommitMsgHook implements MessageHook
{
    /**
     * `tipo(ámbito opcional)!: descripción`.
     *
     * El `!` opcional marca el breaking change; el ámbito acepta minúsculas,
     * dígitos y los separadores que se usan para nombrar un módulo o una ruta
     * (`feat(users)`, `fix(auth/2fa)`, `docs(ops.deploy)`).
     */
    private const string PATTERN = '/^(feat|fix|chore|docs|refactor|test|perf|ci|build|style|revert)(\([a-z0-9\-\.\/]+\))?!?: .+/';

    /**
     * Mensajes que escribe git por su cuenta y que nadie debería tener que
     * reescribir: los merges, el `Revert "…"` de `git revert` y los `fixup!` /
     * `squash!` / `amend!` que existen precisamente para desaparecer en el
     * rebase interactivo.
     */
    private const string GENERATED_BY_GIT = '/^(Merge |Revert "|fixup! |squash! |amend! )/';

    public Command $command;

    public function getName(): string
    {
        return 'Conventional commits (R43)';
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    public function handle(CommitMessage $message, Closure $next): mixed
    {
        $subject = $this->subject($message->getMessage());

        // Un mensaje vacío lo rechaza git por su cuenta, y con mejor error.
        if ($subject === '' || $this->isExempt($subject) || $this->isConventional($subject)) {
            return $next($message);
        }

        $this->explain($subject);

        throw new HookFailException;
    }

    /**
     * Primera línea útil del mensaje: se saltan las líneas en blanco y los
     * comentarios que git añade al archivo (`# Please enter the commit
     * message…`, la lista de archivos del `git commit -v`…).
     */
    private function subject(string $message): string
    {
        foreach (explode("\n", $message) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return $line;
        }

        return '';
    }

    private function isExempt(string $subject): bool
    {
        return preg_match(self::GENERATED_BY_GIT, $subject) === 1;
    }

    private function isConventional(string $subject): bool
    {
        return preg_match(self::PATTERN, $subject) === 1;
    }

    private function explain(string $subject): void
    {
        $output = $this->command->getOutput();

        $this->command->newLine();
        $output->writeln('<fg=red>El asunto del commit no sigue Conventional Commits (R43):</>');
        $output->writeln("  {$subject}");
        $this->command->newLine();
        $output->writeln('  Formato: <fg=green>tipo(ámbito opcional)!: descripción</>');
        $output->writeln('  Tipos:   feat, fix, chore, docs, refactor, test, perf, ci, build, style, revert');
        $output->writeln('  Ejemplo: <fg=green>feat(users): añade el filtro por rol a la tabla</>');
        $this->command->newLine();
        $output->writeln('  El `!` antes de los dos puntos marca un breaking change: <fg=green>refactor(users)!: …</>');
        $output->writeln('  Ver docs/architecture/rules.md · R43.');
    }
}
