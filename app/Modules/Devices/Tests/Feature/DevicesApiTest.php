<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Devices\Enums\Platform;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Providers\DevicesModuleServiceProvider;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| API de dispositivos
|--------------------------------------------------------------------------
|
| Los tres endpoints de `api/v1/devices`, contra el contrato de R54: envelope
| `{ data, meta? }` en éxito, `{ error: { code, message } }` en fallo y códigos
| canónicos (`unauthenticated`, `not_found`, `validation_failed`).
|
| Aquí NO se usa `Sanctum::actingAs()`: ese helper inyecta un `TransientToken`,
| que no tiene fila ni id, y la mitad de lo que se prueba —`current`, la
| revocación del token de la petición, el push token del dispositivo en uso—
| depende de que el token sea uno de verdad. Los tests mandan el bearer.
|
*/

/**
 * Enciende el módulo sobre la aplicación en marcha y ejecuta el callback.
 *
 * **Por qué no `withEnvironment()`**: ese helper rearranca la aplicación, y
 * `RefreshDatabase` deja abierta una transacción sobre el PDO en memoria que la
 * conexión nueva ya no contabiliza (`Connection::setPdo()` pone el nivel a 0).
 * El primer `DB::transaction()` de dentro —el de `DeviceRevokeAction`— intenta
 * un `BEGIN` sobre una conexión que ya está en transacción y revienta con
 * «cannot start a transaction within a transaction».
 *
 * Registrar el provider a mano evita el rearranque y prueba exactamente lo
 * mismo: que con el módulo encendido los endpoints se comportan como dice el
 * contrato. Que el toggle los encienda y los apague es asunto de
 * `DevicesToggleTest`, que sí usa `withEnvironment()` porque allí no hay
 * transacciones.
 */
function withDevicesApiOn(Closure $callback): void
{
    Config::set('kore-app.devices.enabled', true);
    Config::set('kore-app.api.enabled', true);

    app()->register(DevicesModuleServiceProvider::class, force: true);

    $callback();
}

/**
 * Usuario con un token de Sanctum y su dispositivo ya inventariado.
 *
 * @return array{0: User, 1: string, 2: Device}
 */
function deviceApiActor(string $name = 'iPhone de Ada'): array
{
    $user = User::factory()->create();
    $token = $user->createToken($name);

    $device = Device::factory()->create([
        'user_id' => $user->getKey(),
        'name' => $name,
        'platform' => Platform::Ios,
        'app_version' => '2.4.0',
        'access_token_id' => $token->accessToken->getKey(),
    ]);

    return [$user, $token->plainTextToken, $device];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/devices
|--------------------------------------------------------------------------
*/

it('rechaza a quien no manda token', function (): void {
    withDevicesApiOn(function (): void {
        $this->getJson('/api/v1/devices')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });
});

it('lista sólo los dispositivos del usuario del token', function (): void {
    withDevicesApiOn(function (): void {
        [$ada, $bearer] = deviceApiActor();
        Device::factory()->count(2)->create(['user_id' => $ada->getKey()]);
        Device::factory()->count(3)->create(); // de otras cuentas

        $response = $this->withToken($bearer)->getJson('/api/v1/devices')->assertOk();

        expect($response->json('data'))->toHaveCount(3)
            ->and($response->json('meta'))->toHaveKeys(['next_cursor', 'prev_cursor', 'per_page']);
    });
});

it('publica exactamente los campos del contrato y ninguno más', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer] = deviceApiActor();

        $device = $this->withToken($bearer)->getJson('/api/v1/devices')->assertOk()->json('data.0');

        expect(array_keys($device))->toBe([
            'uuid', 'name', 'platform', 'app_version', 'last_seen_at', 'revoked_at', 'current',
        ])
            ->and($device['platform'])->toBe(['value' => 'ios', 'label' => 'iOS']);
    });
});

it('nunca publica el push token', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer, $device] = deviceApiActor();
        $device->update(['push_token' => 'token-de-notificaciones-secretisimo']);

        $this->withToken($bearer)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertDontSee('token-de-notificaciones-secretisimo')
            ->assertJsonMissingPath('data.0.push_token');
    });
});

it('marca current sólo en el dispositivo que hace la petición', function (): void {
    withDevicesApiOn(function (): void {
        [$ada, $bearer, $actual] = deviceApiActor();
        $otro = Device::factory()->create(['user_id' => $ada->getKey()]);

        $data = collect($this->withToken($bearer)->getJson('/api/v1/devices')->assertOk()->json('data'))
            ->keyBy('uuid');

        expect($data[$actual->uuid]['current'])->toBeTrue()
            ->and($data[$otro->uuid]['current'])->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/devices/{device:uuid}
|--------------------------------------------------------------------------
*/

it('revoca un dispositivo propio y borra su token', function (): void {
    withDevicesApiOn(function (): void {
        [$ada, $bearer] = deviceApiActor();

        $otroToken = $ada->createToken('iPad');
        $otro = Device::factory()->create([
            'user_id' => $ada->getKey(),
            'access_token_id' => $otroToken->accessToken->getKey(),
        ]);

        $this->withToken($bearer)
            ->deleteJson("/api/v1/devices/{$otro->uuid}")
            ->assertNoContent();

        expect($otro->fresh()?->revoked_at)->not->toBeNull()
            ->and(PersonalAccessToken::query()->whereKey($otroToken->accessToken->getKey())->exists())->toBeFalse();
    });
});

it('cerrar la sesión del dispositivo actual invalida el token de la propia petición', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer, $actual] = deviceApiActor();

        $this->withToken($bearer)
            ->deleteJson("/api/v1/devices/{$actual->uuid}")
            ->assertNoContent();

        // El guard de Sanctum cachea el usuario que ya resolvió, y en un test
        // las dos peticiones comparten aplicación: sin esto, la segunda seguiría
        // viendo al usuario de la primera aunque su token ya no exista.
        $this->app->make('auth')->forgetGuards();

        // El 204 se responde y la siguiente llamada ya no pasa.
        $this->withToken($bearer)->getJson('/api/v1/devices')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    });
});

it('responde 404 —no 403— ante el dispositivo de otra cuenta', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer] = deviceApiActor();
        $ajeno = Device::factory()->create();

        // Un 403 confirmaría que ese uuid existe. Mismo criterio que passkeys.
        $this->withToken($bearer)
            ->deleteJson("/api/v1/devices/{$ajeno->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        expect($ajeno->fresh()?->revoked_at)->toBeNull();
    });
});

it('responde 404 ante un uuid que no existe', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer] = deviceApiActor();

        $this->withToken($bearer)
            ->deleteJson('/api/v1/devices/00000000-0000-4000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    });
});

/*
|--------------------------------------------------------------------------
| PUT /api/v1/devices/current/push-token
|--------------------------------------------------------------------------
*/

it('guarda el push token del dispositivo en uso y no lo devuelve', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer, $device] = deviceApiActor();

        $this->withToken($bearer)
            ->putJson('/api/v1/devices/current/push-token', ['push_token' => 'fcm-token-nuevo'])
            ->assertNoContent();

        expect($device->fresh()?->push_token)->toBe('fcm-token-nuevo');

        $this->withToken($bearer)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertDontSee('fcm-token-nuevo');
    });
});

it('sólo toca el dispositivo del token, no los demás de la cuenta', function (): void {
    withDevicesApiOn(function (): void {
        [$ada, $bearer, $actual] = deviceApiActor();
        $otro = Device::factory()->create(['user_id' => $ada->getKey(), 'push_token' => 'el-de-antes']);

        $this->withToken($bearer)
            ->putJson('/api/v1/devices/current/push-token', ['push_token' => 'fcm-token-nuevo'])
            ->assertNoContent();

        expect($actual->fresh()?->push_token)->toBe('fcm-token-nuevo')
            ->and($otro->fresh()?->push_token)->toBe('el-de-antes');
    });
});

it('devuelve 422 con details cuando falta el push token', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer] = deviceApiActor();

        $this->withToken($bearer)
            ->putJson('/api/v1/devices/current/push-token', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['push_token']]]);
    });
});

it('devuelve 404 cuando el token en uso no tiene dispositivo registrado', function (): void {
    withDevicesApiOn(function (): void {
        // Un token creado desde el panel web: no hay dispositivo detrás, así que
        // decirle 204 sería mentirle a un cliente que se cree registrado.
        $user = User::factory()->create();
        $bearer = $user->createToken('token del panel')->plainTextToken;

        $this->withToken($bearer)
            ->putJson('/api/v1/devices/current/push-token', ['push_token' => 'fcm-token-nuevo'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    });
});

it('no deja actualizar el push token de un dispositivo revocado', function (): void {
    withDevicesApiOn(function (): void {
        [, $bearer, $device] = deviceApiActor();
        $device->update(['revoked_at' => now()]);

        $this->withToken($bearer)
            ->putJson('/api/v1/devices/current/push-token', ['push_token' => 'fcm-token-nuevo'])
            ->assertNotFound();

        expect($device->fresh()?->push_token)->not->toBe('fcm-token-nuevo');
    });
});

/*
|--------------------------------------------------------------------------
| Documentación OpenAPI
|--------------------------------------------------------------------------
|
| Que los tres endpoints salgan en `/api/docs.json` no se prueba aquí sino en
| `tests/Feature/Api/ApiDocsTest.php`, que es donde vive el toggle `API_DOCS` y
| su gate `viewApiDocs`. Con `DEVICES_ENABLED=true` Scramble los documenta solo:
| el filtro de `Scramble::configure()->routes(...)` mira `api/v*` y no lleva
| ninguna lista de módulos.
|
*/
