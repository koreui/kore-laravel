<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Core\Data\Data;

/**
 * Alta pública de una cuenta (registro por el propio interesado).
 *
 * La validación la hace el adaptador de Fortify (`Auth\Fortify\CreateNewUser`),
 * que es quien conoce el formato del input HTTP; aquí sólo viajan datos ya
 * validados.
 *
 * `invitationCode` viaja sólo cuando `AUTH_INVITATIONS` está encendido, y es
 * `null` en cualquier otro caso: el DTO describe lo que el formulario mandó, no
 * lo que el toggle exige.
 */
final class RegisterData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $invitationCode = null,
    ) {}
}
