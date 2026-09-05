<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Dónde están los tokens de push de una persona.
 *
 * Existe para que el canal de push de `App\Modules\Notifications` pueda
 * preguntar «¿a qué aparatos mando esto?» sin importar una sola clase del
 * módulo `Devices`, que es quien de verdad guarda esos tokens (la columna
 * `push_token` de la tabla `devices`, alimentada por
 * `DevicePushTokenUpdateAction`). Sin este contrato, Notifications tendría que
 * importar `Devices\Models\Device` y R5 dejaría de ser una regla.
 *
 * **El binding lo pone Devices y sólo con `DEVICES_ENABLED=true`**
 * (`DevicesModuleServiceProvider::register()` → `Devices\Support\DevicePushTokens`).
 * Si no está bindeado, el canal de push no manda nada y lo dice en el log: una
 * instalación sin inventario de dispositivos no tiene a dónde mandar un push, y
 * eso no es un error que deba tumbar el aviso — que ya está en la bandeja.
 *
 * Por eso el consumidor **no** resuelve el contrato a secas: pregunta primero
 * por `$container->bound(PushTokenDirectory::class)`.
 *
 * Ver `docs/modules/notifications.md` y `docs/modules/devices.md`.
 */
interface PushTokenDirectory
{
    /**
     * Los tokens de push activos de un usuario, sin repetir.
     *
     * Devuelve una lista vacía cuando esa persona no tiene ningún aparato con
     * token registrado, que es el caso normal mientras nadie haya aceptado las
     * notificaciones en su móvil.
     *
     * @return array<int, string>
     */
    public function tokensFor(int $userId): array;
}
