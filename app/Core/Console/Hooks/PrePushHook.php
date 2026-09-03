<?php

declare(strict_types=1);

namespace App\Core\Console\Hooks;

use Closure;
use Igorsgm\GitHooks\Contracts\PrePushHook as PrePushHookContract;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Capa 2 de verificación: análisis estático completo + suite de tests.
 *
 * Presupuesto: ~30 s. PHPStan trae dentro Larastan (tipos), PHPat (grafo de
 * dependencias) y disallowed-calls (llamadas prohibidas), así que un solo
 * binario cubre R1, R4-R8, R15, R17-R22 y R27. Pest cubre el comportamiento.
 *
 * Lo que NO corre aquí, a propósito: Rector, `composer audit` y el build de
 * Vite. Son de `composer ci` y de CI; meterlos aquí rompería el presupuesto y
 * la gente empezaría a usar `--no-verify`.
 *
 * Ver docs/architecture/rules.md §Capas de verificación.
 */
final class PrePushHook implements PrePushHookContract
{
    /**
     * Comando → etiqueta para el mensaje de error.
     *
     * @var array<string, string>
     */
    private const array STEPS = [
        'vendor/bin/phpstan analyse --no-progress --memory-limit=2G' => 'Larastan + PHPat + disallowed-calls',
        'vendor/bin/pest --parallel --compact' => 'Pest',
    ];

    public Command $command;

    public function getName(): string
    {
        return 'Kore pre-push (phpstan + pest)';
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    public function handle(Log $log, Closure $next): mixed
    {
        foreach (self::STEPS as $step => $label) {
            // APP_ENV explícito: el proceso hijo hereda el .env cargado por
            // este artisan; phpunit.xml lo fuerza igualmente (force="true"),
            // pero aquí se deja claro que la suite nunca corre como `local`.
            $result = Process::path(base_path())->env(['APP_ENV' => 'testing'])->timeout(600)->run($step);

            if ($result->successful()) {
                continue;
            }

            $this->command->newLine();
            $this->command->getOutput()->writeln("<fg=red>{$label} falló:</>");
            $this->command->getOutput()->write($result->output().$result->errorOutput());

            throw new HookFailException;
        }

        return $next($log);
    }
}
