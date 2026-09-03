<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| devices.version · el corte por versión de cliente
|--------------------------------------------------------------------------
|
| El middleware es OPT-IN por ruta y no está en el grupo `api`, así que aquí se
| monta una ruta de laboratorio dentro del arranque con el toggle encendido.
| Probarlo sobre una ruta real ataría el test a que esa ruta lo lleve puesto, que
| es justo la decisión que el módulo deja abierta.
|
| Lo que se comprueba es el contrato del 426: status, `error.code` y —tan
| importante como lo anterior— que NO lleva `details`, que en este contrato
| significa siempre «errores por campo» y es exclusiva del 422.
|
*/

/**
 * Arranca la aplicación con el módulo encendido, registra la ruta de
 * laboratorio detrás del alias y ejecuta el callback.
 */
function withDevicesVersionGateOn(string $minimum, Closure $callback): void
{
    withEnvironment(['DEVICES_ENABLED' => 'true', 'API_ENABLED' => 'true'], function () use ($minimum, $callback): void {
        Config::set('devices.min_app_version', $minimum);

        Route::middleware(['api', 'devices.version'])
            ->get('/api/v1/laboratorio-version', fn (): array => ['data' => ['ok' => true]]);

        $callback();
    });
}

it('deja pasar al cliente que no manda la cabecera', function (): void {
    withDevicesVersionGateOn('2.0.0', function (): void {
        // Los clientes web no mandan `X-App-Version` y no se actualizan por una
        // tienda: no hay nada que exigirles.
        $this->getJson('/api/v1/laboratorio-version')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    });
});

it('deja pasar al cliente con la cabecera vacía', function (): void {
    withDevicesVersionGateOn('2.0.0', function (): void {
        $this->withHeader('X-App-Version', '  ')
            ->getJson('/api/v1/laboratorio-version')
            ->assertOk();
    });
});

it('deja pasar al cliente en la versión mínima exacta', function (): void {
    withDevicesVersionGateOn('2.0.0', function (): void {
        $this->withHeader('X-App-Version', '2.0.0')
            ->getJson('/api/v1/laboratorio-version')
            ->assertOk();
    });
});

it('deja pasar al cliente por encima de la versión mínima', function (): void {
    withDevicesVersionGateOn('2.0.0', function (): void {
        $this->withHeader('X-App-Version', '2.10.0')
            ->getJson('/api/v1/laboratorio-version')
            ->assertOk();
    });
});

it('responde 426 con el shape de error del contrato al cliente antiguo', function (): void {
    withDevicesVersionGateOn('2.0.0', function (): void {
        $response = $this->withHeader('X-App-Version', '1.9.9')
            ->getJson('/api/v1/laboratorio-version')
            ->assertStatus(426)
            ->assertJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'upgrade_required');

        // `details` es exclusiva del 422 («errores por campo»): un 426 que la
        // llevara obligaría al cliente a comprobar el código antes de pintarla.
        expect($response->json('error'))->not->toHaveKey('details')
            ->and($response->json('error.message'))->toContain('1.9.9')
            ->and($response->json('error.message'))->toContain('2.0.0');
    });
});

it('no corta a nadie con la versión mínima por defecto', function (): void {
    withDevicesVersionGateOn('0.0.0', function (): void {
        $this->withHeader('X-App-Version', '0.0.1')
            ->getJson('/api/v1/laboratorio-version')
            ->assertOk();
    });
});
