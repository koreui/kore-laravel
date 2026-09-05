<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Livewire;

use App\Core\Concerns\HandlesDeleteConfirmation;
use App\Modules\Webhooks\Actions\WebhookEndpointDeleteAction;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookEndpoint;
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
 * El listado de suscriptores, con el estado de su cola.
 *
 * La columna «En cola» sale de un `withCount` y no de una consulta por fila: es
 * lo que separa dos consultas de veinticinco. El contador cuenta las entregas
 * **abiertas** (`pending` y `failed`), que son las que todavía tienen que salir;
 * las agotadas se ven en la pantalla del endpoint, donde alguien puede hacer
 * algo con ellas.
 */
final class TableEndpoints extends KoreDataTable
{
    use HandlesDeleteConfirmation;
    use InteractsWithFeedback;

    /** @return Builder<WebhookEndpoint> */
    public function query(): Builder
    {
        /** @var Builder<WebhookEndpoint> $query */
        $query = WebhookEndpoint::query()
            ->withCount([
                'deliveries as open_deliveries_count' => fn (Builder $q): Builder => $q
                    ->whereIn('status', array_column(DeliveryStatus::open(), 'value')),
            ]);

        return $query;
    }

    #[Override]
    public function configure(): void
    {
        $this->setDefaultSort('created_at', 'desc');
        $this->setTableLayout('fixed');
    }

    #[On('webhooks-updated')]
    public function refresh(): void
    {
        // Re-render
    }

    public function columns(): array
    {
        return [
            Column::make(__('Nombre'), 'name')
                ->sortable()
                ->searchable()
                ->description('url'),

            // La URL no tiene columna propia: va como segunda línea de
            // «Nombre». Se declara igual para que la búsqueda global la
            // alcance, porque WithSearch recorre las columnas declaradas y no
            // sólo las visibles.
            Column::make(__('URL'), 'url')
                ->searchable()
                ->hidden(),

            Column::make(__('Eventos'), 'id')
                ->format(fn (mixed $value, WebhookEndpoint $row): string => $row->subscribesTo(WebhookEndpoint::ALL_EVENTS)
                    ? __('Todos')
                    : (string) count($row->subscribed_events))
                ->width(120),

            BadgeColumn::make(__('Estado'), 'active')
                ->format(fn (mixed $value, WebhookEndpoint $row): string => $row->active ? __('Activo') : __('Inactivo'))
                ->colors([
                    'Activo' => 'success',
                    'Inactivo' => 'muted',
                ])
                ->width(120),

            Column::make(__('En cola'), 'open_deliveries_count')
                ->sortable()
                ->width(110),

            DateColumn::make(__('Creado'), 'created_at')->sortable(),

            ActionColumn::make()->actions([
                RowAction::make('show', __('Ver entregas'))
                    ->icon('list')
                    ->urlPattern('/webhooks/{uuid}'),

                RowAction::make('edit', __('Editar'))
                    ->icon('pencil')
                    ->urlPattern('/webhooks/{uuid}/edit'),

                RowAction::make('delete', __('Eliminar'))
                    ->icon('trash')
                    ->color('destructive')
                    ->wireMethod('confirmDelete')
                    ->confirm(__('¿Eliminar este endpoint y todas sus entregas?'))
                    ->separator(),
            ]),
        ];
    }

    /** @return array<int, Filter> */
    #[Override]
    public function filters(): array
    {
        return [
            SelectFilter::make(__('Estado'), 'active')
                ->options([
                    ['value' => '1', 'label' => __('Activo')],
                    ['value' => '0', 'label' => __('Inactivo')],
                ])
                ->callback(fn (Builder $query, mixed $value): Builder => $query->where('active', '=', $value === '1')),
        ];
    }

    /**
     * El hook de `HandlesDeleteConfirmation`: el trait recibe el id del
     * `RowAction`, lo guarda con `#[Locked]` y llama aquí.
     *
     * La autorización tiene que vivir en este método y no en la ruta: la llamada
     * viaja por `/livewire/update`, donde el `permission:webhooks.manage` del
     * archivo de rutas no corre (R23).
     *
     * La Action se resuelve a mano y NO por inyección de método: cuando el
     * diálogo de confirmación acepta, quien invoca es
     * `InteractsWithFeedback::handleConfirmCallback()` de koreUi, que hace
     * `$this->{$method}(...$params)` directo, sin pasar por el contenedor.
     */
    public function deleteAuthorized(int $id): void
    {
        $endpoint = WebhookEndpoint::query()->find($id);

        if (! $endpoint instanceof WebhookEndpoint) {
            return;
        }

        $this->authorize('delete', $endpoint);

        resolve(WebhookEndpointDeleteAction::class)->handle($endpoint);

        $this->toast()
            ->success(__('¡Listo!'), __('Endpoint eliminado.'))
            ->send();

        $this->dispatch('webhooks-updated');
    }
}
