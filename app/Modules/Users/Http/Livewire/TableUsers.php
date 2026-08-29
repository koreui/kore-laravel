<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Models\User;
use App\Modules\Auth\Models\Role;
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

final class TableUsers extends KoreDataTable
{
    use InteractsWithFeedback;

    /** @return Builder<User> */
    public function query(): Builder
    {
        /** @var Builder<User> $query */
        $query = User::query()
            ->with('roles')
            ->whereDoesntHave('roles', fn (Builder $q): Builder => $q->where('name', '=', Role::SUPERADMIN));

        return $query;
    }

    #[Override]
    public function configure(): void
    {
        $this->setDefaultSort('created_at', 'desc');

        // Sin `table-layout: fixed` el navegador reparte los anchos por contenido
        // y el `width(80)` de la columna «#» se ignora.
        $this->setTableLayout('fixed');
    }

    #[On('users-updated')]
    public function refresh(): void
    {
        // Re-render
    }

    public function columns(): array
    {
        return [
            Column::make('#', 'id')->sortable()->width(80),

            Column::make(__('Usuario'), 'name')
                ->sortable()
                ->searchable()
                ->description('email'),

            // El email no tiene columna propia: va como segunda línea de «Usuario».
            // Se declara igualmente para que la búsqueda global siga alcanzándolo,
            // porque WithSearch recorre todas las columnas declaradas, no solo las
            // visibles.
            Column::make(__('Email'), 'email')
                ->searchable()
                ->hidden(),

            BadgeColumn::make(__('Rol'), 'id')
                ->format(fn (mixed $value, User $row): string => (string) ($row->roles->first()?->getAttribute('name') ?? __('Sin rol')))
                ->colors([
                    Role::ADMIN => 'primary',
                    Role::USER => 'secondary',
                ]),

            DateColumn::make(__('Creado'), 'created_at')->sortable(),

            ActionColumn::make()->actions([
                RowAction::make('edit', __('Editar'))
                    ->icon('pencil')
                    ->urlPattern('/users/{id}/edit')
                    ->hidden(fn (mixed $row): bool => ! auth()->user()?->can('users.edit')),

                RowAction::make('delete', __('Eliminar'))
                    ->icon('trash')
                    ->color('destructive')
                    ->wireMethod('confirmDelete')
                    ->confirm(__('¿Eliminar este usuario?'))
                    ->hidden(fn (mixed $row): bool => $row['id'] === auth()->id() || ! auth()->user()?->can('users.delete'))
                    ->separator(),
            ]),
        ];
    }

    /** @return array<int, Filter> */
    #[Override]
    public function filters(): array
    {
        return [
            SelectFilter::make(__('Rol'), 'role')
                ->options(Role::allRoles())
                ->callback(fn (Builder $query, mixed $value): Builder => $query->whereHas(
                    'roles',
                    fn (Builder $q): Builder => $q->where('name', '=', $value),
                )),
        ];
    }

    public function confirmDelete(int $id): void
    {
        $user = User::find($id);

        if (! $user instanceof User || $user->id === auth()->id()) {
            return;
        }

        $user->delete();

        $this->toast()
            ->success(__('¡Listo!'), __('Usuario eliminado.'))
            ->send();

        $this->dispatch('users-updated');
    }
}
