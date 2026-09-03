<?php

declare(strict_types=1);

namespace App\Modules\Devices\Listeners;

use App\Modules\Auth\Events\ApiTokenIssued;
use App\Modules\Devices\Actions\DeviceRegisterAction;
use App\Modules\Devices\Data\DeviceRegistrationData;
use App\Modules\Devices\Enums\Platform;

/**
 * Auth emite un token, Devices apunta desde dónde.
 *
 * Es toda la relación entre los dos módulos (R5): `App\Modules\Auth\Events\*` es
 * la única parte de Auth que este módulo importa, y Auth no sabe que Devices
 * existe. Apagar `DEVICES_ENABLED` deja de registrar este listener y el login
 * por API sigue funcionando exactamente igual.
 *
 * **Sin `deviceId` no hay dispositivo.** Un token creado desde el panel web o
 * por un `php artisan` no identifica ningún aparato, y registrar uno con un id
 * inventado llenaría el inventario de filas que nadie puede revocar porque
 * nadie sabe a qué corresponden.
 */
final readonly class RegisterDeviceOnTokenIssued
{
    public function __construct(private DeviceRegisterAction $action) {}

    public function handle(ApiTokenIssued $event): void
    {
        if ($event->deviceId === null || $event->deviceId === '') {
            return;
        }

        $this->action->handle($event->user, new DeviceRegistrationData(
            deviceId: $event->deviceId,
            // El nombre del token es lo que el cliente eligió llamarse
            // («iPhone de Ada»): es la etiqueta que el usuario reconoce en la
            // lista de sus dispositivos.
            name: $event->tokenName,
            platform: $this->resolvePlatform($event->platform),
            appVersion: $event->appVersion,
            accessTokenId: $event->tokenId,
        ));
    }

    /**
     * La plataforma sólo cuenta si es un caso del enum **y** está en la lista
     * blanca de `config('devices.platforms')`.
     *
     * Lo que no cuadra se guarda como `null` en vez de reventar: la plataforma
     * es metadato para que el usuario reconozca su aparato, no una decisión de
     * autorización, y un login que falla porque un cliente mandó `windows` es
     * peor que un dispositivo sin icono.
     */
    private function resolvePlatform(?string $platform): ?Platform
    {
        if ($platform === null) {
            return null;
        }

        $allowed = array_values(array_filter((array) config('devices.platforms', []), is_string(...)));

        if (! in_array($platform, $allowed, true)) {
            return null;
        }

        return Platform::tryFrom($platform);
    }
}
