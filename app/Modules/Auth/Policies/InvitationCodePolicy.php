<?php

declare(strict_types=1);

namespace App\Modules\Auth\Policies;

use App\Models\User;

/**
 * Quién reparte invitaciones.
 *
 * Un solo permiso —`invitations.manage`— para las tres habilidades, y es
 * deliberado: repartir un código y revocarlo son la misma decisión vista desde
 * los dos lados, y separarlas produciría el rol que puede abrir la puerta pero
 * no cerrarla.
 *
 * `delete` es «revocar»: la fila no se borra nunca (ver
 * `InvitationRevokeAction`), pero el verbo de la policy es el que Laravel
 * espera para una operación destructiva y el que ya usa el resto del
 * boilerplate.
 *
 * OJO: `AuthModuleServiceProvider` registra un `Gate::before` que devuelve true
 * para el rol superadmin, así que para ese rol esta policy nunca se evalúa.
 */
final class InvitationCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invitations.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('invitations.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('invitations.manage');
    }
}
