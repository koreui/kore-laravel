<?php

declare(strict_types=1);

namespace App\Modules\Auth\Events;

use App\Models\User;

/**
 * Una cuenta acaba de pasar a `active` y su dueño ya puede entrar.
 *
 * Es la frontera pública de Auth para el estado de alta (R5): un derivado que
 * quiera mandar el correo de bienvenida, avisar por Slack o dar de alta al
 * usuario en un tercero escucha esto y no importa nada más del módulo.
 *
 * Lo dispara quien activa, sea quien sea: `InvitationRedeemAction` cuando
 * alguien canjea un código, y `Users\Actions\UserAccountStatusChangeAction`
 * cuando una persona pulsa «Activar» en el panel. Por eso `activatedBy` es
 * opcional — en un alta por invitación no hay nadie al otro lado.
 */
final readonly class AccountActivated
{
    public function __construct(
        public User $user,
        public ?int $activatedBy = null,
    ) {}
}
