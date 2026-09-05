<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Auth\Data\InvitationData;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Crea un código de invitación.
 *
 * El actor llega por parámetro y no de `auth()` (R19): así el mismo caso de uso
 * sirve desde la pantalla, desde un comando de alta masiva o desde un seeder.
 *
 * Si `InvitationData::$code` viene nulo se genera uno y se reintenta ante una
 * colisión. Reintentar y no «generar hasta que no exista» es deliberado: la
 * comprobación previa no es atómica —dos peticiones simultáneas pueden mirar,
 * ver libre y chocar igual—, y quien decide de verdad es el índice único de la
 * tabla.
 */
final class InvitationCreateAction extends Action
{
    /**
     * Cuántas veces se reintenta con un código nuevo antes de rendirse.
     *
     * Con 8 caracteres en mayúsculas y dígitos, tres colisiones seguidas no son
     * mala suerte: son que la tabla está llena o que algo va mal. Rendirse con
     * una excepción es mejor que un bucle infinito en una petición web.
     */
    private const int MAX_ATTEMPTS = 3;

    public function handle(InvitationData $data, User $creator): InvitationCode
    {
        $expiresAt = $data->expiresAt === null
            ? null
            : CarbonImmutable::parse($data->expiresAt);

        $explicitCode = $data->code === null ? null : InvitationCode::normalize($data->code);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = $explicitCode ?? InvitationCode::generate();

            if (InvitationCode::query()->where('code', '=', $code)->exists()) {
                // Un código escrito a mano y repetido no es una colisión de
                // azar: es un error del formulario, y reintentar daría el mismo
                // resultado tres veces.
                if ($explicitCode !== null) {
                    break;
                }

                continue;
            }

            return InvitationCode::create([
                'code' => $code,
                'role' => $data->role,
                'max_uses' => $data->maxUses,
                'uses' => 0,
                'expires_at' => $expiresAt,
                'created_by' => $creator->id,
                'note' => $data->note,
            ]);
        }

        throw new RuntimeException('No se pudo generar un código de invitación libre.');
    }
}
