<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/*
|--------------------------------------------------------------------------
| Logging de producción — stderr en JSON
|--------------------------------------------------------------------------
|
| En Docker los logs no van a un archivo: van a stderr y los recoge el runtime
| (`docker compose logs -f app`), con la rotación json-file del compose. La
| receta del `.env` de producción es:
|
|   LOG_CHANNEL=stack
|   LOG_STACK=stderr            (o `stderr,sentry`)
|   LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
|   LOG_LEVEL=warning
|
| `config/logging.php` ya trae el canal `stderr`; lo que este test blinda es que
| esas cuatro variables construyan de verdad el logger que se espera —que
| `formatter` admita un nombre de clase, que el handler escriba en
| `php://stderr` y que `LOG_LEVEL` llegue al handler—. Un formatter mal escrito
| no falla: Laravel deja el LineFormatter por defecto y en producción salen
| líneas de texto donde el agregador espera JSON.
|
*/

/** @return StreamHandler el único handler del canal `stderr` */
function stderrHandler(): StreamHandler
{
    Log::forgetChannel('stderr');

    $logger = Log::channel('stderr')->getLogger();

    expect($logger)->toBeInstanceOf(Logger::class);

    $handler = $logger->getHandlers()[0];

    expect($handler)->toBeInstanceOf(StreamHandler::class);

    return $handler;
}

it('writes the stderr channel to php://stderr', function (): void {
    expect(stderrHandler()->getUrl())->toBe('php://stderr');
});

it('uses the json formatter when LOG_STDERR_FORMATTER names one', function (): void {
    Config::set('logging.channels.stderr.formatter', JsonFormatter::class);

    expect(stderrHandler()->getFormatter())->toBeInstanceOf(JsonFormatter::class);
});

it('falls back to the line formatter when LOG_STDERR_FORMATTER is empty', function (): void {
    // Sin la variable en el entorno, `env('LOG_STDERR_FORMATTER')` es null y
    // Laravel deja el LineFormatter por defecto. Es el estado en el que corre
    // la suite, y el que hay que evitar en producción.
    expect(config('logging.channels.stderr.formatter'))->toBeNull()
        ->and(stderrHandler()->getFormatter())->toBeInstanceOf(LineFormatter::class);
});

it('honours LOG_LEVEL on the stderr handler', function (): void {
    Config::set('logging.channels.stderr.level', 'warning');

    expect(stderrHandler()->getLevel())->toBe(Level::Warning);
});

it('builds the production stack out of stderr', function (): void {
    Config::set('logging.channels.stderr.formatter', JsonFormatter::class);
    Config::set('logging.channels.stack.channels', ['stderr']);

    Log::forgetChannel('stack');
    Log::forgetChannel('stderr');

    $handlers = Log::channel('stack')->getLogger()->getHandlers();

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBeInstanceOf(StreamHandler::class)
        ->and($handlers[0]->getUrl())->toBe('php://stderr')
        ->and($handlers[0]->getFormatter())->toBeInstanceOf(JsonFormatter::class);
});
