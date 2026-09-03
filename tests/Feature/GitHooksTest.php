<?php

declare(strict_types=1);

use App\Core\Console\Hooks\ArchCheckPreCommitHook;
use App\Core\Console\Hooks\ConventionalCommitMsgHook;
use App\Core\Console\Hooks\PrePushCommand;
use App\Core\Console\Hooks\PrePushHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\ChangedFiles;
use Igorsgm\GitHooks\Git\CommitMessage;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
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

/*
|--------------------------------------------------------------------------
| commit-msg · Conventional commits (R43)
|--------------------------------------------------------------------------
*/

/**
 * Pasa un mensaje por el hook. Devuelve `true` si el commit sigue adelante y
 * lanza `HookFailException` si el hook lo aborta.
 */
function commitMessagePasses(string $message): bool
{
    $hook = new ConventionalCommitMsgHook;
    $hook->setCommand(hookCommand());

    $next = false;
    $hook->handle(new CommitMessage($message, new ChangedFiles('')), function () use (&$next): bool {
        $next = true;

        return true;
    });

    return $next;
}

it('el hook de commit-msg acepta un asunto convencional', function (string $message): void {
    expect(commitMessagePasses($message))->toBeTrue();
})->with([
    'sin ámbito' => 'feat: añade el filtro por rol',
    'con ámbito' => 'feat(users): añade el filtro por rol',
    'ámbito con separadores' => 'fix(auth/2fa): recupera los códigos de recuperación',
    'breaking change' => 'refactor(users)!: el DTO deja de aceptar arrays',
    'breaking change sin ámbito' => 'feat!: cambia el contrato del catálogo',
    'chore' => 'chore(release): v1.4.0',
    'docs' => 'docs: explica la regla de tres',
    'test' => 'test(backup): cubre el zip cifrado',
    'perf' => 'perf: evita el N+1 de la tabla de usuarios',
    'ci' => 'ci: publica el release desde el CHANGELOG',
    'build' => 'build(docker): añade mysql-client',
    'style' => 'style: pint sobre los providers',
    'revert' => 'revert: vuelve al middleware anterior',
    'con cuerpo debajo' => "feat(users): añade el filtro por rol\n\nEl cuerpo puede decir lo que quiera.\nRefs: #12",
]);

it('el hook de commit-msg aborta un asunto que no lo es', function (string $message): void {
    $hook = new ConventionalCommitMsgHook;
    $hook->setCommand(hookCommand());

    expect(fn (): mixed => $hook->handle(new CommitMessage($message, new ChangedFiles('')), fn (): bool => true))
        ->toThrow(HookFailException::class);
})->with([
    'sin tipo' => 'arreglos varios',
    'tipo inventado' => 'wip: a medias',
    'sin los dos puntos' => 'feat añade el filtro',
    'sin descripción' => 'feat:',
    'tipo en mayúsculas' => 'Feat: añade el filtro',
    'ámbito en mayúsculas' => 'feat(Users): añade el filtro',
    'con espacio antes de los dos puntos' => 'feat : añade el filtro',
    'el tipo no abre la línea' => 'por fin feat: añade el filtro',
]);

it('el hook de commit-msg deja pasar lo que escribe git por su cuenta', function (string $message): void {
    expect(commitMessagePasses($message))->toBeTrue();
})->with([
    'merge' => "Merge branch 'main' into feat/dx",
    'merge de pull request' => 'Merge pull request #12 from koreui/feat/dx',
    'revert generado' => 'Revert "feat(users): añade el filtro por rol"',
    'fixup' => 'fixup! feat(users): añade el filtro por rol',
    'squash' => 'squash! feat(users): añade el filtro por rol',
]);

it('el hook de commit-msg mira la primera línea útil, no los comentarios de git', function (): void {
    $message = "\n"
        ."# Please enter the commit message for your changes. Lines starting\n"
        ."# with '#' will be ignored, and an empty message aborts the commit.\n"
        ."\n"
        ."feat(dx): unifica los skills en .agents/skills\n";

    expect(commitMessagePasses($message))->toBeTrue();
});

it('el hook de commit-msg no se mete con un mensaje vacío: de eso ya se ocupa git', function (): void {
    expect(commitMessagePasses("\n# todo comentarios\n"))->toBeTrue();
});

it('el hook de commit-msg está registrado en config/git-hooks.php', function (): void {
    expect(config('git-hooks.commit-msg'))->toContain(ConventionalCommitMsgHook::class);
});

/*
 * El script que instala igorsgm/laravel-git-hooks reenvía con `$@` los dos
 * argumentos que git pasa al pre-push (remote y url), pero el comando del
 * paquete no los declara y todo push moría antes de correr el hook.
 * App\Core\Console\Hooks\PrePushCommand lo reemplaza con la firma correcta.
 */
it('accepts the remote and url arguments git passes to the pre-push hook', function (): void {
    $definition = Artisan::all()['git-hooks:pre-push']->getDefinition();

    expect(Artisan::all()['git-hooks:pre-push'])->toBeInstanceOf(PrePushCommand::class)
        ->and($definition->hasArgument('remote'))->toBeTrue()
        ->and($definition->hasArgument('url'))->toBeTrue()
        ->and($definition->getArgument('remote')->isRequired())->toBeFalse();
});
