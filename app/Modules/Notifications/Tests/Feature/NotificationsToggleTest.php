<?php

declare(strict_types=1);

use App\Core\Contracts\Notifier;
use App\Models\User;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Notifications\Console\Commands\NotificationsPruneCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra nada
| observable: ni el binding de `Notifier`, ni las rutas web y de API, ni los
| tres componentes Livewire, ni el listener del evento de Auth, ni el comando,
| ni su entrada en el scheduler.
|
| Dos excepciones documentadas, y las dos se comprueban aquí:
|
|   - el ESQUEMA: `notifications` y `notification_preferences` se migran igual
|     (un toggle apaga rutas y comportamiento, nunca la forma de la base);
|   - el ESPACIO DE VISTAS: `notifications::` se registra siempre, que es lo que
|     permite a Larastan validar `view('notifications::pages.index')`. Sin
|     componentes Livewire registrados nadie puede montar esas vistas, así que
|     registrarlas no expone nada.
|
| La suite corre con el toggle apagado (`phpunit.xml` lo fuerza a false), así
| que los casos "encendido" arrancan la aplicación de nuevo con
| `withEnvironment()` (tests/Pest.php).
|
*/

/**
 * Arranca la aplicación con el módulo encendido.
 *
 * @param array<string, string> $env variables extra para este arranque
 */
function withNotificationsToggleOn(Closure $callback, array $env = []): void
{
    withEnvironment(['NOTIFICATIONS_ENABLED' => 'true', ...$env], $callback);
}

/**
 * Nombres de los comandos programados en el scheduler.
 *
 * @return Collection<int, string>
 */
function notificationsScheduledCommands(): Collection
{
    return collect(resolve(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command);
}

/*
|--------------------------------------------------------------------------
| Toggle apagado (estado por defecto de la suite)
|--------------------------------------------------------------------------
*/

it('keeps the toggle off by default in the suite', function (): void {
    expect(config('kore-app.notifications.enabled'))->toBeFalse();
});

it('does not bind the Notifier contract with the toggle off', function (): void {
    expect(app()->bound(Notifier::class))->toBeFalse();

    // Resolverlo lanza, que es la respuesta correcta: «esta instalación no
    // notifica» es mejor que un aviso que desaparece en silencio.
    expect(fn (): mixed => resolve(Notifier::class))
        ->toThrow(BindingResolutionException::class);
});

it('registers no notifications route with the toggle off', function (): void {
    expect(Route::has('notifications.index'))->toBeFalse()
        ->and(Route::has('notifications.preferences'))->toBeFalse()
        ->and(Route::has('api.v1.me.notifications.index'))->toBeFalse();

    $this->get('/notifications')->assertNotFound();
});

it('does not register the livewire components with the toggle off', function (): void {
    // La campana no existe ni como alias, así que el layout no puede pintarla
    // por accidente.
    expect(fn (): mixed => Livewire::test('notifications.bell'))->toThrow(Exception::class);
});

it('does not register the prune command with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('notifications:prune');
});

it('schedules nothing notifications related with the toggle off', function (): void {
    expect(notificationsScheduledCommands()->contains(
        fn (string $command): bool => str_contains($command, 'notifications:prune'),
    ))->toBeFalse();
});

it('does not listen to the Auth token event with the toggle off', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('CLI de Ada');

    event(new ApiTokenIssued(
        user: $user,
        tokenId: (int) $token->accessToken->getKey(),
        tokenName: 'CLI de Ada',
    ));

    expect($user->notifications()->count())->toBe(0);
});

it('migrates both tables even with the toggle off', function (): void {
    // El esquema NO depende del toggle: si dependiera, encender el módulo en
    // producción exigiría una migración a mano con tráfico encima.
    expect(config('kore-app.notifications.enabled'))->toBeFalse()
        ->and(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasColumns('notifications', ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at']))->toBeTrue()
        ->and(Schema::hasTable('notification_preferences'))->toBeTrue()
        ->and(Schema::hasColumns('notification_preferences', ['user_id', 'category', 'in_app', 'mail', 'push']))->toBeTrue();
});

it('registers the view namespace even with the toggle off', function (): void {
    // Segunda excepción de R10: sin esto Larastan no puede validar
    // `view('notifications::pages.index')`, y un namespace de vistas que nadie
    // puede montar no expone nada.
    expect(View::exists('notifications::pages.index'))->toBeTrue()
        ->and(View::exists('notifications::pages.preferences'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('binds the Notifier contract with the toggle on', function (): void {
    withNotificationsToggleOn(function (): void {
        expect(app()->bound(Notifier::class))->toBeTrue()
            ->and(resolve(Notifier::class))->toBeInstanceOf(Notifier::class);
    });
});

it('registers the web routes with the toggle on', function (): void {
    withNotificationsToggleOn(function (): void {
        expect(Route::has('notifications.index'))->toBeTrue()
            ->and(Route::has('notifications.preferences'))->toBeTrue()
            ->and(route('notifications.index', absolute: false))->toBe('/notifications')
            ->and(route('notifications.preferences', absolute: false))->toBe('/notifications/preferences');
    });
});

it('registers the api routes with the toggle on', function (): void {
    withNotificationsToggleOn(function (): void {
        expect(Route::has('api.v1.me.notifications.index'))->toBeTrue()
            ->and(Route::has('api.v1.me.notifications.read'))->toBeTrue()
            ->and(Route::has('api.v1.me.notifications.read-all'))->toBeTrue()
            ->and(Route::has('api.v1.me.notification-preferences.index'))->toBeTrue()
            ->and(Route::has('api.v1.me.notification-preferences.update'))->toBeTrue();
    }, ['API_ENABLED' => 'true']);
});

it('keeps the api routes off when the API is off, toggle or not', function (): void {
    // Dos toggles, dos preguntas distintas: un derivado puede querer la bandeja
    // web sin publicar la API.
    withNotificationsToggleOn(function (): void {
        expect(config('kore-app.notifications.enabled'))->toBeTrue()
            ->and(config('kore-app.api.enabled'))->toBeFalse()
            ->and(Route::has('api.v1.me.notifications.index'))->toBeFalse()
            // ...pero el resto del módulo sí está.
            ->and(Route::has('notifications.index'))->toBeTrue()
            ->and(array_keys(Artisan::all()))->toContain('notifications:prune');
    }, ['API_ENABLED' => 'false']);
});

it('registers the prune command and schedules it with the toggle on', function (): void {
    withNotificationsToggleOn(function (): void {
        expect(array_keys(Artisan::all()))->toContain('notifications:prune')
            ->and(Artisan::all()['notifications:prune'])->toBeInstanceOf(NotificationsPruneCommand::class)
            ->and(notificationsScheduledCommands()->contains(
                fn (string $command): bool => str_contains($command, 'notifications:prune'),
            ))->toBeTrue();
    });
});

it('listens to the Auth token event with the toggle on', function (): void {
    withNotificationsToggleOn(function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('CLI de Ada');

        event(new ApiTokenIssued(
            user: $user,
            tokenId: (int) $token->accessToken->getKey(),
            tokenName: 'CLI de Ada',
        ));

        expect($user->unreadNotifications()->count())->toBe(1);
    });
});
