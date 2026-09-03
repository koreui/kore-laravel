<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Providers\DevicesModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| devices:cleanup
|--------------------------------------------------------------------------
|
| Tres pasos —revocar abandonados, borrar sus tokens, purgar los revocados
| antiguos— y dos relojes distintos: `stale_after_days` cuenta desde la última
| vez que se vio el dispositivo, `prune_after_days` desde que se revocó.
|
| El `--dry-run` (App\Core\Console\Concerns\SupportsDryRun) es la mitad que más
| importa: es lo que alguien corre la primera vez en producción, y si contara
| bien pero escribiera algo dejaría de ser un ensayo.
|
*/

/**
 * Enciende el módulo sobre la aplicación en marcha, fija los dos plazos —para
 * que el test no dependa de los defaults de `config/devices.php`— y ejecuta el
 * callback.
 *
 * **Por qué no `withEnvironment()`**: ese helper rearranca la aplicación, y
 * `RefreshDatabase` deja abierta una transacción sobre el PDO en memoria que la
 * conexión nueva ya no contabiliza (`Connection::setPdo()` pone el nivel a 0).
 * El `DB::transaction()` del comando intenta entonces un `BEGIN` sobre una
 * conexión que ya está en transacción. Registrar el provider a mano prueba lo
 * mismo sin rearrancar; que el toggle registre o no el comando es asunto de
 * `DevicesToggleTest`.
 */
function withDevicesCleanupOn(Closure $callback, int $staleAfterDays = 180, int $pruneAfterDays = 90): void
{
    Config::set('kore-app.devices.enabled', true);
    Config::set('devices.stale_after_days', $staleAfterDays);
    Config::set('devices.prune_after_days', $pruneAfterDays);

    app()->register(DevicesModuleServiceProvider::class, force: true);

    $callback();
}

/** La salida del comando con los saltos de línea de `components` normalizados. */
function devicesCleanupOutput(): string
{
    return (string) preg_replace('/\s+/', ' ', Artisan::output());
}

/*
|--------------------------------------------------------------------------
| --dry-run
|--------------------------------------------------------------------------
*/

it('el ensayo cuenta lo que haría y no escribe nada', function (): void {
    withDevicesCleanupOn(function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('viejo');

        $abandonado = Device::factory()->lastSeenDaysAgo(200)->create([
            'user_id' => $user->getKey(),
            'access_token_id' => $token->accessToken->getKey(),
        ]);
        $antiguo = Device::factory()->revoked(120)->create(['user_id' => $user->getKey()]);

        $exit = Artisan::call('devices:cleanup', ['--dry-run' => true]);
        $output = devicesCleanupOutput();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Simulacro (--dry-run)')
            ->and($output)->toContain('se revocarían 1 dispositivo(s)')
            ->and($output)->toContain('1 dispositivo(s) revocados antes de')
            // Y ni una escritura: ni la revocación, ni el borrado, ni el token.
            ->and($abandonado->fresh()?->revoked_at)->toBeNull()
            ->and($antiguo->fresh())->not->toBeNull()
            ->and(PersonalAccessToken::query()->whereKey($token->accessToken->getKey())->exists())->toBeTrue();
    });
});

it('añade --dry-run sin que el comando lo declare en su firma', function (): void {
    withDevicesCleanupOn(function (): void {
        $definition = Artisan::all()['devices:cleanup']->getDefinition();

        expect($definition->hasOption('dry-run'))->toBeTrue()
            ->and($definition->getOption('dry-run')->acceptValue())->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| La corrida de verdad
|--------------------------------------------------------------------------
*/

it('revoca los dispositivos abandonados y borra sus tokens', function (): void {
    withDevicesCleanupOn(function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('teléfono vendido');

        $abandonado = Device::factory()->lastSeenDaysAgo(200)->create([
            'user_id' => $user->getKey(),
            'access_token_id' => $token->accessToken->getKey(),
        ]);

        Artisan::call('devices:cleanup');

        expect($abandonado->fresh()?->revoked_at)->not->toBeNull()
            ->and(PersonalAccessToken::query()->whereKey($token->accessToken->getKey())->exists())->toBeFalse();
    });
});

it('deja en paz a los dispositivos con actividad reciente', function (): void {
    withDevicesCleanupOn(function (): void {
        $vivo = Device::factory()->lastSeenDaysAgo(3)->create();

        Artisan::call('devices:cleanup');

        expect($vivo->fresh()?->revoked_at)->toBeNull();
    });
});

it('revoca al que se registró y no volvió nunca', function (): void {
    withDevicesCleanupOn(function (): void {
        // Sin `last_seen_at` el reloj lo pone `created_at`: si no, un
        // dispositivo que nunca apareció quedaría vivo para siempre.
        $device = Device::factory()->create([
            'last_seen_at' => null,
            'created_at' => CarbonImmutable::now()->subDays(200),
        ]);

        Artisan::call('devices:cleanup');

        expect($device->fresh()?->revoked_at)->not->toBeNull();
    });
});

it('purga los revocados hace más del plazo de retención', function (): void {
    withDevicesCleanupOn(function (): void {
        $antiguo = Device::factory()->revoked(120)->create();
        $reciente = Device::factory()->revoked(10)->create();

        Artisan::call('devices:cleanup');

        expect(Device::query()->whereKey($antiguo->getKey())->exists())->toBeFalse()
            ->and(Device::query()->whereKey($reciente->getKey())->exists())->toBeTrue();
    });
});

it('no purga en la misma corrida lo que acaba de revocar', function (): void {
    withDevicesCleanupOn(function (): void {
        // Los dos relojes son distintos a propósito: primero se revoca por
        // silencio y sólo después empieza a correr la retención.
        $device = Device::factory()->lastSeenDaysAgo(400)->create();

        Artisan::call('devices:cleanup');

        expect(Device::query()->whereKey($device->getKey())->exists())->toBeTrue()
            ->and($device->fresh()?->revoked_at)->not->toBeNull();
    });
});

it('borra el token caducado que todavía cuelga de un dispositivo', function (): void {
    withDevicesCleanupOn(function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('caducado');
        $token->accessToken->forceFill(['expires_at' => CarbonImmutable::now()->subDay()])->save();

        Device::factory()->create([
            'user_id' => $user->getKey(),
            'access_token_id' => $token->accessToken->getKey(),
        ]);

        Artisan::call('devices:cleanup');

        expect(PersonalAccessToken::query()->whereKey($token->accessToken->getKey())->exists())->toBeFalse();
    });
});

it('respeta los plazos de config/devices.php', function (): void {
    withDevicesCleanupOn(function (): void {
        // Con `stale_after_days` a 30, un dispositivo callado 40 días ya cae; con
        // el default de 180 habría sobrevivido.
        $device = Device::factory()->lastSeenDaysAgo(40)->create();

        Artisan::call('devices:cleanup');

        expect($device->fresh()?->revoked_at)->not->toBeNull();
    }, staleAfterDays: 30, pruneAfterDays: 10);
});

it('informa del recuento cuando sí escribe', function (): void {
    withDevicesCleanupOn(function (): void {
        Device::factory()->lastSeenDaysAgo(200)->create();
        Device::factory()->revoked(120)->create();

        Artisan::call('devices:cleanup');

        expect(devicesCleanupOutput())
            ->toContain('1 revocado(s)')
            ->toContain('1 dispositivo(s) purgado(s)');
    });
});
