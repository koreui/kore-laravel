<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Policies;

use App\Models\User;

/**
 * Policy del módulo Webhooks.
 *
 * **Un solo permiso, `webhooks.manage`, y no el CRUD de cuatro.** Ver la lista
 * de endpoints ya enseña a qué sistemas se les está contando lo que pasa aquí
 * dentro, y quien puede verla puede leer el payload de cada entrega. No hay un
 * «sólo lectura» que sea menos sensible que el resto: o se administra la
 * integración, o no se entra.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve true
 * para el rol superadmin, así que para ese rol esta policy NUNCA se evalúa.
 *
 * Los métodos no reciben el endpoint porque **no lo miran**: la decisión es la
 * misma para todos. Laravel se lo pasa igual y PHP descarta el argumento de
 * más; declararlo para no usarlo sería sugerir una regla por fila que aquí no
 * existe. El día que la haya —endpoints por equipo, por ejemplo—, el parámetro
 * vuelve con ella.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('webhooks.manage');
    }
}
