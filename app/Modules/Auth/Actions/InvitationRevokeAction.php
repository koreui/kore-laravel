<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;

/**
 * Revoca un código: deja de aceptar registros a partir de ahora.
 *
 * Revocar es **adelantar la caducidad**, no borrar la fila ni apagar un
 * booleano. Borrarla perdería el rastro de por dónde entró cada usuario, y un
 * `activo = false` sería un segundo estado que puede contradecir a
 * `expires_at`. Con una sola fecha, «revocado» y «caducado» son lo mismo para
 * quien pregunta y siguen siendo distinguibles para quien audita: el
 * `updated_at` dice cuándo se decidió.
 *
 * Es idempotente: revocar dos veces mueve la fecha, no rompe nada. Y un código
 * ya caducado no se «des-caduca» por revocarlo otra vez, porque `now()` siempre
 * es posterior a un vencimiento pasado.
 */
final class InvitationRevokeAction extends Action
{
    public function handle(InvitationCode $invitation): InvitationCode
    {
        $invitation->forceFill(['expires_at' => CarbonImmutable::now()])->save();

        return $invitation;
    }
}
