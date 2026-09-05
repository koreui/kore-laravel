<?php

declare(strict_types=1);

use App\Core\Contracts\PushTokenDirectory;
use App\Models\User;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Providers\DevicesModuleServiceProvider;
use App\Modules\Devices\Support\DevicePushTokens;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| DevicePushTokens — el directorio que consume Notifications
|--------------------------------------------------------------------------
|
| Es la implementación de `App\Core\Contracts\PushTokenDirectory` y toda la
| relación entre Devices y Notifications (R5): el módulo que manda push nunca
| importa `Device`, pregunta por esta interfaz de Core.
|
| El binding va detrás de `DEVICES_ENABLED`, así que quien lo consume pregunta
| primero por `bound()`. Eso también se comprueba aquí.
|
*/

it('devuelve los tokens de los dispositivos activos de esa persona', function (): void {
    [$ada, $lin] = [User::factory()->create(), User::factory()->create()];

    Device::factory()->create(['user_id' => $ada->getKey(), 'push_token' => 'token-ada-1']);
    Device::factory()->create(['user_id' => $ada->getKey(), 'push_token' => 'token-ada-2']);
    Device::factory()->create(['user_id' => $lin->getKey(), 'push_token' => 'token-lin']);

    expect(resolve(DevicePushTokens::class)->tokensFor((int) $ada->getKey()))
        ->toEqualCanonicalizing(['token-ada-1', 'token-ada-2']);
});

it('deja fuera los dispositivos revocados', function (): void {
    // Un dispositivo revocado es un teléfono vendido, perdido o cuya sesión
    // alguien cerró a propósito: seguir mandándole push sería hablarle al
    // aparato del que se quiso desconectar.
    $ada = User::factory()->create();

    Device::factory()->revoked()->create(['user_id' => $ada->getKey(), 'push_token' => 'token-viejo']);
    Device::factory()->create(['user_id' => $ada->getKey(), 'push_token' => 'token-vivo']);

    expect(resolve(DevicePushTokens::class)->tokensFor((int) $ada->getKey()))->toBe(['token-vivo']);
});

it('deja fuera los dispositivos sin token', function (): void {
    $ada = User::factory()->create();

    Device::factory()->create(['user_id' => $ada->getKey(), 'push_token' => null]);

    expect(resolve(DevicePushTokens::class)->tokensFor((int) $ada->getKey()))->toBe([]);
});

it('no repite un token que aparece en dos filas', function (): void {
    // Pasa cuando alguien reinstala la app y el servicio de push le devuelve el
    // mismo identificador: sin el `unique` recibiría el aviso por duplicado.
    $ada = User::factory()->create();

    Device::factory()->count(2)->create(['user_id' => $ada->getKey(), 'push_token' => 'token-repetido']);

    expect(resolve(DevicePushTokens::class)->tokensFor((int) $ada->getKey()))->toBe(['token-repetido']);
});

it('no bindea el contrato con el toggle apagado', function (): void {
    expect(config('kore-app.devices.enabled'))->toBeFalse()
        ->and(app()->bound(PushTokenDirectory::class))->toBeFalse();
});

it('bindea el contrato con el toggle encendido', function (): void {
    Config::set('kore-app.devices.enabled', true);

    app()->register(DevicesModuleServiceProvider::class, force: true);

    expect(app()->bound(PushTokenDirectory::class))->toBeTrue()
        ->and(resolve(PushTokenDirectory::class))->toBeInstanceOf(DevicePushTokens::class);
});
