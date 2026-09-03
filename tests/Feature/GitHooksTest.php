<?php

declare(strict_types=1);

use App\Core\Console\Hooks\ArchCheckPreCommitHook;
use App\Core\Console\Hooks\PrePushHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\ChangedFiles;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| Hooks de git
|--------------------------------------------------------------------------
|
| Se prueba la decisión del hook —seguir o abortar el commit / el push—, no el
| contenido de las herramientas que invoca: `kore:arch:check` tiene sus 20
| tests en ArchCheckCommandTest y PHPStan y Pest se prueban a sí mismos.
|
| Por qué no se prueba con un commit real: haría falta escribir un archivo con
| una violación dentro de `app/` o `tests/`, y con `pest --parallel` ese
| archivo lo vería el proceso que corre los arch tests de verdad. El fixture
| sería una bomba de relojería en la propia suite.
|
| Ver docs/architecture/rules.md §Capas de verificación.
|
*/

/**
 * Un `Command` con salida capturable, que es lo que la pipeline de
 * igorsgm/laravel-git-hooks le inyecta al hook.
 */
function hookCommand(int $exitCode = Command::SUCCESS): Command
{
    $command = new class($exitCode) extends Command
    {
        /** @var string */
        protected $signature = 'test:hook-host';

        /** @var array<int, array{0: string, 1: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private readonly int $exitCode)
        {
            parent::__construct();
        }

        /**
         * @param string $command
         * @param array<string, mixed> $arguments
         */
        public function call($command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            return $this->exitCode;
        }
    };

    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

it('el hook de pre-commit pasa los archivos staged a kore:arch:check', function (): void {
    $hook = new ArchCheckPreCommitHook;
    $command = hookCommand(Command::SUCCESS);
    $hook->setCommand($command);

    $next = false;
    $hook->handle(new ChangedFiles("M  composer.json\nM  phpstan.neon\n"), function () use (&$next): bool {
        $next = true;

        return true;
    });

    expect($next)->toBeTrue()
        ->and($command->calls)->toHaveCount(1)
        ->and($command->calls[0][0])->toBe('kore:arch:check')
        ->and($command->calls[0][1]['--files'])->toBe('composer.json,phpstan.neon');
});

it('el hook de pre-commit aborta el commit cuando kore:arch:check falla', function (): void {
    $hook = new ArchCheckPreCommitHook;
    $hook->setCommand(hookCommand(Command::FAILURE));

    $hook->handle(new ChangedFiles("M  composer.json\n"), fn (): bool => true);
})->throws(HookFailException::class);

it('el hook de pre-commit no invoca nada cuando el commit no toca archivos existentes', function (): void {
    $hook = new ArchCheckPreCommitHook;
    $command = hookCommand(Command::FAILURE);
    $hook->setCommand($command);

    $next = false;
    $hook->handle(new ChangedFiles("D  archivo/que/ya/no/existe.php\n"), function () use (&$next): bool {
        $next = true;

        return true;
    });

    expect($next)->toBeTrue()->and($command->calls)->toBe([]);
});

it('el hook de pre-push corre phpstan y pest, en ese orden', function (): void {
    Process::fake();

    $hook = new PrePushHook;
    $hook->setCommand(hookCommand());

    $next = false;
    $hook->handle(new Log(''), function () use (&$next): bool {
        $next = true;

        return true;
    });

    expect($next)->toBeTrue();

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'phpstan analyse'), 1);
    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'pest --parallel'), 1);
});

it('el hook de pre-push aborta el push y no llega a Pest si PHPStan falla', function (): void {
    Process::fake([
        '*phpstan*' => Process::result(output: 'Found 1 error', exitCode: 1),
        '*' => Process::result(exitCode: 0),
    ]);

    $hook = new PrePushHook;
    $hook->setCommand(hookCommand());

    try {
        $hook->handle(new Log(''), fn (): bool => true);
        $this->fail('el hook debería haber lanzado HookFailException');
    } catch (HookFailException) {
        // Esperado.
    }

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'pest'), 0);
});
