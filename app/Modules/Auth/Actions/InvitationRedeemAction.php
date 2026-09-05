<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Core\Actions\Action;
use App\Core\Contracts\AuthorizationCatalog;
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
 *
 * ## El rol se vuelve a comprobar aquí
 *
 * `InvitationForm` ya sólo deja crear códigos con un rol asignable, pero entre
 * que se reparte un código y alguien lo canjea pasan semanas, y en ese hueco el
 * catálogo puede haber cambiado: un rol renombrado, uno retirado, o uno que
 * dejó de ser asignable. La fila `invitation_codes.role` es texto plano y no
 * tiene clave foránea contra `roles`, así que nada la actualiza sola. Sin esta
 * comprobación, `syncRoles()` con un rol desconocido lanzaría una excepción de
 * Spatie a mitad de un registro —y con uno que ya no debería repartirse,
 * peor: entraría—. Defensa en profundidad, con el mismo `AuthorizationCatalog`
 * que usó el formulario.
 */
final class InvitationRedeemAction extends Action
{
    public function __construct(private readonly AuthorizationCatalog $catalog) {}

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

            if (! in_array($invitation->role, $this->catalog->assignableRoleNames(), true)) {
                throw new ConflictException(__('El rol de este código de invitación ya no existe. Pide uno nuevo.'));
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
