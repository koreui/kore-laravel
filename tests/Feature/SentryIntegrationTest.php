<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Sentry\Laravel\Integration;

it('exposes a sentry logging channel', function (): void {
    expect(config('logging.channels.sentry.driver'))->toBe('sentry')
        ->and(config('logging.channels.sentry.level'))->toBe('error');
});

it('keeps the sentry channel out of the default stack', function (): void {
    // El canal se activa en producción con LOG_STACK=single,sentry.
    expect(config('logging.channels.stack.channels'))->not->toContain('sentry');
});

it('registers the sentry reportable callback on the exception handler', function (): void {
    $handler = resolve(ExceptionHandler::class);

    $reportCallbacks = new ReflectionObject($handler)
        ->getProperty('reportCallbacks')
        ->getValue($handler);

    $files = array_map(function (object $reportable): string {
        $callback = new ReflectionObject($reportable)
            ->getProperty('callback')
            ->getValue($reportable);

        return new ReflectionFunction($callback)->getFileName() ?: '';
    }, $reportCallbacks);

    $fromSentry = array_filter($files, fn (string $file): bool => str_contains($file, 'sentry-laravel'));

    expect($fromSentry)->not->toBeEmpty();
})->skip(
    fn (): bool => ! property_exists(resolve(ExceptionHandler::class), 'reportCallbacks'),
    'El handler de excepciones no expone reportCallbacks en esta versión de Laravel.',
);

it('loads the sentry integration class', function (): void {
    // Límite del test: sólo comprueba que la clase está cargable y que
    // bootstrap/app.php la referencia. No podemos verificar que Sentry reciba
    // el evento sin un DSN real y un transport falso.
    expect(class_exists(Integration::class))->toBeTrue()
        ->and(file_get_contents(base_path('bootstrap/app.php')))
        ->toContain('Integration::handles($exceptions)');
});
