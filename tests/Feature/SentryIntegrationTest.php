<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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

/*
|--------------------------------------------------------------------------
| Cron monitors — `->sentryMonitor()` en routes/console.php
|--------------------------------------------------------------------------
|
| Un scheduler sin monitor falla en silencio: el cron deja de correr, el backup
| deja de hacerse y nadie se entera hasta el día que hace falta.
| `sentryMonitor()` convierte cada tarea en un check-in de Sentry, que avisa
| cuando **no** llega.
|
| Cómo se detecta. La macro no deja marca en el evento: lo que hace es
| registrar un `before` (y un `onSuccess`/`onFailure`) con el check-in dentro.
| Ese closure captura por `use` un `$monitorSlug` y un `$startCheckIn`, y esa
| pareja de nombres no la produce ningún otro método del scheduler. Es acoplado
| a la implementación de sentry-laravel a propósito: la alternativa —comprobar
| que el texto de routes/console.php dice `sentryMonitor()`— no probaría que la
| llamada surte efecto, que es justo lo que se rompe al reordenar una cadena.
|
*/

/**
 * Nombres de las tareas programadas que NO llevan `->sentryMonitor()`.
 *
 * @return array<int, string>
 */
function tareasSinMonitorDeSentry(): array
{
    $sinMonitor = [];

    foreach (resolve(Schedule::class)->events() as $event) {
        $callbacks = new ReflectionObject($event)
            ->getProperty('beforeCallbacks')
            ->getValue($event);

        $tieneMonitor = collect((array) $callbacks)->contains(function (Closure $callback): bool {
            $captured = array_keys(new ReflectionFunction($callback)->getStaticVariables());

            return in_array('monitorSlug', $captured, true) && in_array('startCheckIn', $captured, true);
        });

        if (! $tieneMonitor) {
            $sinMonitor[] = (string) $event->command;
        }
    }

    return $sinMonitor;
}

it('registers the sentryMonitor macro even without a DSN', function (): void {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and(Event::hasMacro('sentryMonitor'))->toBeTrue();
});

it('puts a sentry monitor on every scheduled task', function (): void {
    expect(resolve(Schedule::class)->events())->not->toBeEmpty()
        ->and(tareasSinMonitorDeSentry())->toBe([]);
});

it('puts a sentry monitor on the backup tasks too', function (): void {
    withEnvironment(['BACKUP_ENABLED' => 'true'], function (): void {
        $commands = collect(resolve(Schedule::class)->events())
            ->map(fn (Event $event): string => (string) $event->command);

        expect($commands->contains(fn (string $command): bool => str_contains($command, 'backup:run')))->toBeTrue()
            ->and(tareasSinMonitorDeSentry())->toBe([]);
    });
});

/*
 * Sin DSN, `onBootInactive()` deja `shouldHandleCheckIn` en false y el check-in
 * se va por el `return` de su primera línea: la tarea corre igual y no hay
 * ninguna llamada de red que fingir en los tests.
 */
it('keeps the monitor a no-op while there is no DSN', function (): void {
    foreach (resolve(Schedule::class)->events() as $event) {
        $event->callBeforeCallbacks(app());
    }
})->throwsNoExceptions();
