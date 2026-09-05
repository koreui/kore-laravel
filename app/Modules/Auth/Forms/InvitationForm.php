<?php

declare(strict_types=1);

namespace App\Modules\Auth\Forms;

use App\Core\Contracts\AuthorizationCatalog;
use App\Modules\Auth\Data\InvitationData;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Livewire Form Object del alta de un código de invitación.
 *
 * Valida y empaqueta; escribir es trabajo de `InvitationCreateAction` (R4).
 *
 * Sólo hay alta: un código no se edita. Cambiarle el rol o el cupo después de
 * repartirlo cambiaría el trato con quien ya lo tiene en la mano, y la
 * alternativa —revocar y crear otro— deja el rastro de las dos decisiones.
 *
 * El rol sale de `AuthorizationCatalog::assignableRoleNames()`, que excluye
 * superadmin: un código es una credencial que se reparte por WhatsApp, y el rol
 * con bypass total del `Gate::before` no viaja así (R26).
 */
final class InvitationForm extends Form
{
    public string $role = '';

    public ?int $max_uses = null;

    public ?string $expires_at = null;

    public ?string $note = null;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(resolve(AuthorizationCatalog::class)->assignableRoleNames())],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return [
            'role' => __('rol'),
            'max_uses' => __('límite de registros'),
            'expires_at' => __('fecha de caducidad'),
            'note' => __('nota'),
        ];
    }

    /**
     * Estado del formulario como DTO. Llamar SIEMPRE después de `validate()`:
     * `InvitationData` no valida nada.
     */
    public function toData(): InvitationData
    {
        return new InvitationData(
            role: $this->role,
            maxUses: $this->max_uses,
            expiresAt: $this->expires_at === '' ? null : $this->expires_at,
            note: $this->note === '' ? null : $this->note,
        );
    }
}
