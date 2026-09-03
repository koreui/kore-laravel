<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Auth\Events\ApiTokenRevoked;
use App\Modules\Devices\Enums\Platform;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Devices escucha a Auth, y nada más
|--------------------------------------------------------------------------
|
| R5 · toda la relación entre los dos módulos son estos dos eventos. Lo que se
| prueba aquí es el contrato desde el lado que escucha: qué pasa con cada
| combinación de datos que Auth puede publicar, incluidas las que no traen
| dispositivo.
|
| Los tests crean tokens de Sanctum de verdad y no ids inventados: la columna
| `access_token_id` tiene FK contra `personal_access_tokens`, así que un id
| falso no llegaría ni a insertarse.
|
*/

/**
 * Arranca la aplicación con el módulo encendido y ejecuta el callback.
 *
 * Cada archivo de tests del módulo define el suyo con un nombre propio: con
 * `--parallel` cada proceso carga sólo los archivos que le tocan, así que una
 * función compartida entre archivos no está garantizada (y dos con el mismo
 * nombre en el mismo proceso serían una redeclaración).
 */
function withDevicesListenersOn(Closure $callback): void
{
    withEnvironment(['DEVICES_ENABLED' => 'true', 'API_ENABLED' => 'true'], $callback);
}

/**
 * Usuario con un token de Sanctum recién emitido.
 *
 * @return array{0: User, 1: int}
 */
function deviceListenerActor(string $name = 'iPhone de Ada'): array
{
    $user = User::factory()->create();
    $token = $user->createToken($name);

    return [$user, (int) $token->accessToken->getKey()];
}

/*
|--------------------------------------------------------------------------
| ApiTokenIssued
|--------------------------------------------------------------------------
*/

it('registra el dispositivo cuando el evento trae deviceId', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $tokenId] = deviceListenerActor();

        event(new ApiTokenIssued(
            user: $user,
            tokenId: $tokenId,
            tokenName: 'iPhone de Ada',
            deviceId: 'ABC-123',
            platform: 'ios',
            appVersion: '2.4.0',
        ));

        $device = Device::query()->where('device_id', 'ABC-123')->firstOrFail();

        expect($device->user_id)->toBe($user->getKey())
            ->and($device->name)->toBe('iPhone de Ada')
            ->and($device->platform)->toBe(Platform::Ios)
            ->and($device->app_version)->toBe('2.4.0')
            ->and($device->access_token_id)->toBe($tokenId)
            ->and($device->last_seen_at)->not->toBeNull()
            ->and($device->revoked_at)->toBeNull()
            // La identidad pública se rellena sola (HasPublicUuid).
            ->and($device->uuid)->not->toBeEmpty();
    });
});

it('no registra nada cuando el evento no trae deviceId', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $tokenId] = deviceListenerActor();

        // Un token creado desde el panel web o por un comando: no identifica
        // ningún aparato, así que no hay dispositivo que inventariar.
        event(new ApiTokenIssued($user, $tokenId, 'token del panel'));

        expect(Device::count())->toBe(0);
    });
});

it('reutiliza la fila del mismo dispositivo en vez de acumular una por sesión', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $primero] = deviceListenerActor('primer login');
        $segundo = (int) $user->createToken('segundo login')->accessToken->getKey();

        event(new ApiTokenIssued($user, $primero, 'iPhone de Ada', 'ABC-123', 'ios', '2.4.0'));
        event(new ApiTokenIssued($user, $segundo, 'iPhone de Ada', 'ABC-123', 'ios', '2.5.0'));

        $devices = Device::query()->where('user_id', $user->getKey())->get();

        expect($devices)->toHaveCount(1)
            ->and($devices->first()->app_version)->toBe('2.5.0')
            ->and($devices->first()->access_token_id)->toBe($segundo);
    });
});

it('resucita un dispositivo revocado cuando su dueño vuelve a entrar', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $tokenId] = deviceListenerActor();

        $device = Device::factory()->revoked(3)->create([
            'user_id' => $user->getKey(),
            'device_id' => 'ABC-123',
        ]);

        event(new ApiTokenIssued($user, $tokenId, 'iPhone de Ada', 'ABC-123'));

        expect($device->fresh()?->revoked_at)->toBeNull();
    });
});

it('guarda la plataforma como null cuando no está en la lista blanca', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $tokenId] = deviceListenerActor();

        // Un cliente que se inventa la plataforma no debe romper el login: la
        // plataforma es metadato, no una decisión de autorización.
        event(new ApiTokenIssued($user, $tokenId, 'raro', 'ABC-123', 'windows-phone', '1.0.0'));

        expect(Device::query()->where('device_id', 'ABC-123')->firstOrFail()->platform)->toBeNull();
    });
});

it('dos usuarios en el mismo aparato son dos dispositivos', function (): void {
    withDevicesListenersOn(function (): void {
        [$ada, $tokenAda] = deviceListenerActor();
        [$bob, $tokenBob] = deviceListenerActor();

        event(new ApiTokenIssued($ada, $tokenAda, 'compartido', 'MISMO-APARATO'));
        event(new ApiTokenIssued($bob, $tokenBob, 'compartido', 'MISMO-APARATO'));

        expect(Device::query()->where('device_id', 'MISMO-APARATO')->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| ApiTokenRevoked
|--------------------------------------------------------------------------
*/

it('revoca sólo el dispositivo del token cuando el evento trae un id', function (): void {
    withDevicesListenersOn(function (): void {
        [$user, $tokenId] = deviceListenerActor();
        $otroToken = (int) $user->createToken('otro')->accessToken->getKey();

        $suyo = Device::factory()->create(['user_id' => $user->getKey(), 'access_token_id' => $tokenId]);
        $otro = Device::factory()->create(['user_id' => $user->getKey(), 'access_token_id' => $otroToken]);

        event(new ApiTokenRevoked($user, $tokenId, 'logout'));

        expect($suyo->fresh()?->revoked_at)->not->toBeNull()
            ->and($otro->fresh()?->revoked_at)->toBeNull();
    });
});

it('revoca todos los dispositivos del usuario cuando el tokenId es null', function (): void {
    withDevicesListenersOn(function (): void {
        $user = User::factory()->create();
        $ajeno = User::factory()->create();

        $devices = Device::factory()->count(3)->create(['user_id' => $user->getKey()]);
        $deOtro = Device::factory()->create(['user_id' => $ajeno->getKey()]);

        // «Cerrar sesión en todas partes», un cambio de contraseña, una cuenta
        // comprometida: caen todos los suyos y ninguno de nadie más.
        event(new ApiTokenRevoked($user, null, 'logout-all'));

        expect($devices->every(fn (Device $device): bool => $device->fresh()?->revoked_at !== null))->toBeTrue()
            ->and($deOtro->fresh()?->revoked_at)->toBeNull();
    });
});

it('no toca la fecha de un dispositivo ya revocado', function (): void {
    withDevicesListenersOn(function (): void {
        $user = User::factory()->create();
        $revocadoHace = CarbonImmutable::now()->subDays(10);

        $device = Device::factory()->create([
            'user_id' => $user->getKey(),
            'revoked_at' => $revocadoHace,
        ]);

        event(new ApiTokenRevoked($user, null, 'logout-all'));

        // El scope `active()` deja fuera lo ya revocado: si no, cada logout
        // reiniciaría el reloj de retención de `devices:cleanup`.
        expect($device->fresh()?->revoked_at?->toDateTimeString())
            ->toBe($revocadoHace->toDateTimeString());
    });
});
