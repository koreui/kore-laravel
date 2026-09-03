<?php

declare(strict_types=1);

namespace App\Modules\Devices\Policies;

use App\Models\User;
use App\Modules\Devices\Models\Device;

/**
 * Policy de los dispositivos.
 *
 * Un dispositivo no es un recurso administrable: es la sesión abierta de una
 * persona en un aparato suyo. No hay permiso `devices.delete` ni rol que lo
 * alcance — la única regla es la propiedad, igual que en `PasskeyPolicy`.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve
 * `true` para el superadmin, así que para ese rol esta policy **nunca se
 * evalúa**. Por eso `DeviceController` no busca el dispositivo por uuid suelto
 * sino acotando por `user_id`: la propiedad la garantiza la consulta —que
 * además convierte «el de otro» en un 404 en vez de un 403 que confirmaría que
 * ese uuid existe— y la policy es la segunda barrera, no la única.
 */
final class DevicePolicy
{
    /**
     * No hay `viewAny()`, igual que en `PasskeyPolicy`.
     *
     * Listar «los míos» no es una decisión que tomar: cualquiera puede, y el
     * filtro por dueño lo pone la consulta del controller. Un `viewAny()` que
     * devolviera `true` sin mirar nada sería una regla decorativa, y las reglas
     * decorativas son las que un día alguien cambia creyendo que sirven para
     * algo.
     */
    public function view(User $user, Device $device): bool
    {
        return $device->user_id === $user->getKey();
    }

    public function delete(User $user, Device $device): bool
    {
        return $device->user_id === $user->getKey();
    }
}
