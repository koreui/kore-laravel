<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Core\Enums\AccountStatus;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Modules\Auth\Data\RegisterData;
use App\Modules\Auth\Events\AccountActivated;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/**
 * Canjea un código de invitación: crea la cuenta con el rol que dice el código,
 * la deja activa y suma un uso.
 *
 * **Todo dentro de una transacción con la fila bloqueada.** El caso que obliga
 * a ello es un código de un solo uso repartido a dos personas: sin
 * `lockForUpdate()`, las dos leen `uses = 0`, las dos pasan la comprobación y
 * las dos entran. Con el bloqueo, la segunda espera, vuelve a leer `uses = 1` y
 * se lleva el conflicto.
 *
 * Por eso vuelve a comprobar lo que `Auth\Rules\ValidInvitationCode` ya
 * comprobó: la validación responde por lo que se ve al enviar el formulario, y
 * esto responde por lo que hay en el instante de escribir. Cuando el código ya
 * no sirve lanza `ConflictException` —una excepción de dominio, no de Http
 * (R20)— y quien la traduce a un error de formulario es el adaptador de
 * Fortify.
 *
 * La cuenta nace **activa**: presentar un código válido *es* la activación, y
 * dejarla en `pending` obligaría a que alguien aprobara a mano a quien ya fue
 * invitado. Lo que nace `pending` es lo que entra por una puerta que no pide
 * código (el login social), y esa decisión vive en su controlador.
 */
final class InvitationRedeemAction extends Action
{
    public function handle(RegisterData $data, string $code): User
    {
        return DB::transaction(function () use ($data, $code): User {
            $normalized = InvitationCode::normalize($code);

            $invitation = InvitationCode::query()
                ->where('code', '=', $normalized)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof InvitationCode) {
                throw new ConflictException(__('El código de invitación no es válido.'));
            }

            if (! $invitation->isUsable()) {
                throw new ConflictException($invitation->unavailableReason() ?? __('El código de invitación no es válido.'));
            }

            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'account_status' => AccountStatus::Active,
                'activated_at' => CarbonImmutable::now(),
            ]);

            $user->syncRoles([$invitation->role]);

            $invitation->forceFill(['uses' => $invitation->uses + 1])->save();

            Event::dispatch(new AccountActivated($user));

            return $user;
        });
    }
}
