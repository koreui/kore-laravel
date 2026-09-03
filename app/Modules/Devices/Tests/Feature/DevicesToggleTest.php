<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Devices\Console\Commands\DevicesCleanupCommand;
use App\Modules\Devices\Models\Device;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| DEVICES_ENABLED
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado el provider hace `return` y no registra nada
| observable: ni rutas, ni el alias del middleware, ni los listeners de los
| eventos de Auth, ni el comando, ni su entrada en el scheduler.
|
| La excepción documentada es el ESQUEMA: la tabla `devices` se migra igual, y
| eso también se comprueba aquí. Un toggle apaga comportamiento, no la forma de
| la base (ver docs/architecture/toggles.md y el docblock del provider).
|
| La suite corre con el toggle apagado —`.env.example` lo trae en false y ése es
| el default de `config/kore-app.php`—, así que los casos "encendido" arrancan
| la aplicación de nuevo con `withEnvironment()` (tests/Pest.php).
|
*/

/**
 * Arranca la aplicación con el módulo (y la API) encendidos.
 *
 * @param array<string, string> $env variables extra para este arranque
 */
function withDevicesToggleOn(Closure $callback, array $env = []): void
{
    withEnvironment(['DEVICES_ENABLED' => 'true', 'API_ENABLED' => 'true', ...$env], $callback);
}

/**
 * Nombres de los comandos programados en el scheduler.
 *
 * @return Collection<int, string>
 */
function devicesScheduledCommands(): Collection
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
    expect(config('kore-app.devices.enabled'))->toBeFalse();
});

it('registers no devices route with the toggle off', function (): void {
    expect(Route::has('api.v1.devices.index'))->toBeFalse()
        ->and(Route::has('api.v1.devices.destroy'))->toBeFalse()
        ->and(Route::has('api.v1.devices.push-token.update'))->toBeFalse();

    $this->getJson('/api/v1/devices')->assertNotFound();
});

it('does not register the version middleware alias with the toggle off', function (): void {
    expect(Route::getMiddleware())->not->toHaveKey('devices.version');
});

it('does not register the cleanup command with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('devices:cleanup');
});

it('schedules nothing devices related with the toggle off', function (): void {
    expect(devicesScheduledCommands()->contains(fn (string $command): bool => str_contains($command, 'devices:cleanup')))
        ->toBeFalse();
});

it('does not listen to the Auth token events with the toggle off', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone de Ada');

    event(new ApiTokenIssued(
        user: $user,
        tokenId: (int) $token->accessToken->getKey(),
        tokenName: 'iPhone de Ada',
        deviceId: 'device-apagado',
    ));

    expect(Device::count())->toBe(0);
});

it('migrates the devices table even with the toggle off', function (): void {
    // El esquema NO depende del toggle: si dependiera, encender DEVICES_ENABLED
    // en producción exigiría una migración a mano con tráfico encima.
    expect(config('kore-app.devices.enabled'))->toBeFalse()
        ->and(Schema::hasTable('devices'))->toBeTrue()
        ->and(Schema::hasColumns('devices', [
            'uuid', 'user_id', 'device_id', 'name', 'platform', 'app_version',
            'push_token', 'access_token_id', 'last_seen_at', 'revoked_at',
        ]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('registers the devices routes with the toggle on', function (): void {
    withDevicesToggleOn(function (): void {
        expect(config('kore-app.devices.enabled'))->toBeTrue()
            ->and(Route::has('api.v1.devices.index'))->toBeTrue()
            ->and(Route::has('api.v1.devices.destroy'))->toBeTrue()
            ->and(Route::has('api.v1.devices.push-token.update'))->toBeTrue()
            ->and(route('api.v1.devices.index', absolute: false))->toBe('/api/v1/devices');
    });
});

it('registers the version middleware alias with the toggle on', function (): void {
    withDevicesToggleOn(function (): void {
        expect(Route::getMiddleware())->toHaveKey('devices.version');
    });
});

it('registers the cleanup command and schedules it with the toggle on', function (): void {
    withDevicesToggleOn(function (): void {
        expect(array_keys(Artisan::all()))->toContain('devices:cleanup')
            ->and(Artisan::all()['devices:cleanup'])->toBeInstanceOf(DevicesCleanupCommand::class)
            ->and(devicesScheduledCommands()->contains(fn (string $command): bool => str_contains($command, 'devices:cleanup')))
            ->toBeTrue();
    });
});

it('listens to the Auth token events with the toggle on', function (): void {
    withDevicesToggleOn(function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('iPhone de Ada');

        event(new ApiTokenIssued(
            user: $user,
            tokenId: (int) $token->accessToken->getKey(),
            tokenName: 'iPhone de Ada',
            deviceId: 'device-encendido',
        ));

        expect(Device::query()->where('device_id', 'device-encendido')->exists())->toBeTrue();
    });
});

it('keeps the devices routes off when the API is off, toggle or not', function (): void {
    // Dos toggles, dos preguntas distintas: el módulo puede estar encendido y la
    // API apagada (un derivado que sólo quiera el inventario para su scheduler).
    withEnvironment(['DEVICES_ENABLED' => 'true', 'API_ENABLED' => 'false'], function (): void {
        expect(config('kore-app.devices.enabled'))->toBeTrue()
            ->and(config('kore-app.api.enabled'))->toBeFalse()
            ->and(Route::has('api.v1.devices.index'))->toBeFalse()
            // ...pero el resto del módulo sí está: el comando y los listeners no
            // son API.
            ->and(array_keys(Artisan::all()))->toContain('devices:cleanup');
    });
});
