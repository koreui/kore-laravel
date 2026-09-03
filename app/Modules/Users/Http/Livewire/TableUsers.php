<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Users\Actions\UserDeleteAction;
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

    /**
     * Workaround koreUi 2.2: `RowAction::confirm()` arma el diálogo en el
     * cliente y, al aceptar, `handleConfirmCallback()` sólo ejecuta métodos
     * presentes en `$koreConfirmable`, lista que únicamente rellena
     * `Confirm::send()` (camino que las row actions no recorren, a diferencia
     * de las bulk actions). Sin esto `confirmDelete()` nunca se invoca.
     * `hydrate()` corre tras restaurar las propiedades del snapshot, justo
     * antes de despachar el listener. Quitar cuando koreUi autorice las row
     * actions por sí mismo.
     */
    public function hydrate(): void
    {
        if (! in_array('confirmDelete', $this->koreConfirmable, true)) {
            $this->koreConfirmable[] = 'confirmDelete';
        }
    }

    /** @return Builder<User> */
    public function query(): Builder
    {
        /** @var Builder<User> $query */
        $query = User::query()
            ->with('roles')
            ->whereDoesntHave('roles', fn (Builder $q): Builder => $q->where('name', '=', SystemRole::Superadmin->value));

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
                    SystemRole::Admin->value => 'primary',
                    SystemRole::User->value => 'secondary',
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
                ->options(array_map(
                    fn (RoleOptionData $role): array => $role->toArray(),
                    resolve(AuthorizationCatalog::class)->assignableRoles(),
                ))
                ->callback(fn (Builder $query, mixed $value): Builder => $query->whereHas(
                    'roles',
                    fn (Builder $q): Builder => $q->where('name', '=', $value),
                )),
        ];
    }

    /**
     * El `->hidden()` del RowAction es sólo cosmética: /livewire/update no pasa
     * por el middleware `permission:*` de las rutas del módulo, así que la
     * autorización real tiene que hacerse aquí.
     *
     * La Action se resuelve a mano y NO por inyección de método: cuando el
     * diálogo de confirmación acepta, quien invoca es
     * `InteractsWithFeedback::handleConfirmCallback()` de koreUi, que hace
     * `$this->{$method}(...$params)` directo, sin pasar por el contenedor. Un
     * parámetro extra tipado reventaría ahí con ArgumentCountError (los tests
     * de Livewire sí usan el contenedor, así que el fallo sólo aparece en el
     * navegador). Ver el workaround de `hydrate()` más arriba.
     */
    public function confirmDelete(int $id): void
    {
        $user = User::find($id);

        if (! $user instanceof User) {
            return;
        }

        // Guarda explícita de auto-borrado: el Gate::before del superadmin
        // devuelve true antes de consultar la policy, así que sin esto un
        // superadmin podría borrarse a sí mismo desde la tabla.
        abort_if($user->id === auth()->id(), 403);

        $this->authorize('delete', $user);

        resolve(UserDeleteAction::class)->handle($user);

        $this->toast()
            ->success(__('¡Listo!'), __('Usuario eliminado.'))
            ->send();

        $this->dispatch('users-updated');
    }
}
