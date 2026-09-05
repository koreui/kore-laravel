<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire\Invitations;

use App\Modules\Auth\Actions\InvitationRevokeAction;
use App\Modules\Auth\Models\InvitationCode;
use Illuminate\Database\Eloquent\Builder;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use KoreUi\DataTable\Actions\RowAction;
use KoreUi\DataTable\Columns\ActionColumn;
use KoreUi\DataTable\Columns\BadgeColumn;
use KoreUi\DataTable\Columns\Column;
use KoreUi\DataTable\Columns\DateColumn;
use KoreUi\DataTable\Filters\Filter;
use KoreUi\DataTable\Filters\SelectFilter;
use KoreUi\DataTable\KoreDataTable;
use Livewire\Attributes\On;
use Override;

/**
 * Los códigos de invitación repartidos, y cuánta gente entró por cada uno.
 *
 * La columna que responde a la pregunta útil es «Registros»: dice si una
 * campaña sirvió y delata el código que se compartió de más.
 */
final class TableInvitations extends KoreDataTable
{
    use InteractsWithFeedback;

    /** @return Builder<InvitationCode> */
    public function query(): Builder
    {
        return InvitationCode::query()->with('creator');
    }

    #[Override]
    public function configure(): void
    {
        $this->setDefaultSort('created_at', 'desc');
    }

    /**
     * Mismo workaround que `App\Core\Concerns\HandlesDeleteConfirmation`, y por
     * el mismo motivo: en koreUi 2.3 `RowAction::confirm()` arma el diálogo en
     * el cliente, pero al aceptar `InteractsWithFeedback::handleConfirmCallback()`
     * sólo ejecuta los métodos que estén en `$koreConfirmable`, lista que
     * únicamente rellena `Confirm::send()` —camino que las row actions no
     * recorren—. Sin esto, «Revocar» abre el diálogo y no pasa nada al aceptar.
     *
     * No se reutiliza el trait de Core porque revocar **no es borrar**: la fila
     * se queda, con su rastro de quién entró por ella, y sólo deja de aceptar
     * registros nuevos. Llamar `deleteAuthorized()` a eso sería mentir en el
     * nombre por ahorrar seis líneas.
     *
     * Quitar cuando koreUi autorice las row actions por sí mismo.
     */
    public function hydrate(): void
    {
        if (! in_array('revoke', $this->koreConfirmable, true)) {
            $this->koreConfirmable[] = 'revoke';
        }
    }

    #[On('invitations-updated')]
    public function refreshTable(): void
    {
        // Re-render
    }

    public function columns(): array
    {
        return [
            Column::make(__('Código'), 'code')
                ->sortable()
                ->searchable()
                ->maxWidth(160)
                ->format(fn (mixed $value): string => '<span class="font-mono font-semibold">'.e((string) $value).'</span>')
                ->html(),

            Column::make(__('Rol'), 'role')->sortable(),

            Column::make(__('Nota'), 'note')
                ->searchable()
                ->maxWidth(240)
                ->format(fn (mixed $value): string => (string) ($value ?? '—')),

            Column::make(__('Registros'), 'uses')
                ->align('center')
                ->sortable()
                ->format(fn (mixed $value, InvitationCode $row): string => $row->usageLabel()),

            DateColumn::make(__('Caduca'), 'expires_at')
                ->sortable()
                ->format(fn (mixed $value, InvitationCode $row): string => $row->expires_at?->format('d/m/Y H:i') ?? '—'),

            BadgeColumn::make(__('Estado'), 'id')
                ->align('center')
                ->format(fn (mixed $value, InvitationCode $row): string => $row->statusLabel())
                ->colors([
                    'Disponible' => 'success',
                    'Agotado' => 'warning',
                    'Caducado' => 'muted',
                ]),

            DateColumn::make(__('Creado'), 'created_at')->sortable(),

            ActionColumn::make()->inline()->actions([
                RowAction::make('revoke', __('Revocar'))
                    ->icon('ban')
                    ->color('destructive')
                    ->wireMethod('revoke')
                    ->confirm(__('¿Revocar este código? Quien ya se registró con él conserva su cuenta.'))
                    ->hidden(fn (mixed $row): bool => ! auth()->user()?->can('invitations.manage')),
            ]),
        ];
    }

    /** @return array<int, Filter> */
    #[Override]
    public function filters(): array
    {
        return [
            SelectFilter::make(__('Estado'), 'estado')
                ->options([
                    ['value' => 'disponibles', 'label' => __('Disponibles')],
                    ['value' => 'cerrados', 'label' => __('Cerrados')],
                ])
                ->callback(fn (Builder $query, mixed $value): Builder => $value === 'disponibles'
                    ? $query->whereIn('id', InvitationCode::query()->available()->select('id'))
                    : $query->whereNotIn('id', InvitationCode::query()->available()->select('id'))),
        ];
    }

    /**
     * Revoca un código.
     *
     * El `->hidden()` del RowAction es sólo cosmética: `/livewire/update` no
     * pasa por el `permission:invitations.manage` de la ruta, así que la
     * autorización de verdad es este `authorize()` (R23).
     *
     * La Action se resuelve a mano y no por inyección de método: quien invoca
     * tras aceptar el diálogo es `handleConfirmCallback()` de koreUi, que hace
     * `$this->{$method}(...$params)` sin pasar por el contenedor, y un
     * parámetro extra tipado reventaría ahí con `ArgumentCountError`.
     */
    public function revoke(int $id): void
    {
        $this->authorize('delete', InvitationCode::class);

        $invitation = InvitationCode::find($id);

        if (! $invitation instanceof InvitationCode) {
            return;
        }

        resolve(InvitationRevokeAction::class)->handle($invitation);

        $this->toast()
            ->success(__('¡Listo!'), __('El código ya no acepta registros.'))
            ->send();

        $this->dispatch('invitations-updated');
    }
}
