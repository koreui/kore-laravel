<?php

declare(strict_types=1);

namespace App\Modules\Platform\Policies;

use App\Models\User;

/**
 * Policy de los ajustes de la instalación.
 *
 * Sus dos métodos reciben sólo al usuario porque los ajustes son un **singleton
 * conceptual**: no se autoriza «editar esta fila», se autoriza «tocar la
 * configuración». Por eso la pantalla llama `authorize('update', Setting::class)`
 * con la clase y no con un modelo.
 *
 * Un solo permiso, `settings.manage`, y no el CRUD de cuatro que genera
 * `Module` por defecto: aquí no hay nada que crear ni que borrar —una clave sin
 * fila ya existe, vale su defecto— así que `settings.create` y `settings.delete`
 * serían dos permisos que nadie podría comprobar contra nada. Se declara como
 * permiso especial en `Module::specialPermissions()`.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve true
 * para el rol superadmin, así que para ese rol esta policy nunca se evalúa.
 */
final class SettingPolicy
{
    /**
     * Ver la pantalla de ajustes.
     *
     * Es el mismo permiso que editarlos, a propósito: los ajustes incluyen
     * datos de contacto y fiscales de la organización, así que «mirar sin
     * tocar» no es un nivel de acceso que aporte nada — quien puede verlos es
     * quien los administra.
     */
    public function view(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('settings.manage');
    }
}
