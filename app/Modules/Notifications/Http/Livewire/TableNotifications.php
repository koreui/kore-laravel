<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Livewire;

use App\Models\User;
use App\Modules\Notifications\Actions\NotificationMarkAllReadAction;
use App\Modules\Notifications\Actions\NotificationMarkReadAction;
use App\Modules\Notifications\Support\NotificationCategories;
use App\Modules\Notifications\Support\NotificationPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * La bandeja completa.
 *
 * Lista de tarjetas y no `KoreDataTable`: aquí no hay columnas que ordenar como
 * en un catálogo — cada renglón es un mensaje que se lee y se atiende, y la
 * forma de tarjeta comunica eso mejor que una fila de tabla.
 *
 * Los dos filtros van en la URL (`#[Url]`) para que un enlace a «mis no leídas
 * de cuenta» sea compartible y sobreviva a un refresco.
 *
 * **La Blade recibe arrays** (R30): las fechas y las etiquetas de categoría
 * salen resueltas desde aquí, y la paginación viaja aparte porque el paginador
 * sí tiene que llegar entero para pintar sus enlaces.
 */
final class TableNotifications extends Component
{
    use InteractsWithFeedback;
    use WithPagination;

    /** Cuántas por página. */
    private const int PER_PAGE = 15;

    #[Url(as: 'categoria', except: '')]
    public string $category = '';

    #[Url(as: 'sin_leer', except: false)]
    public bool $onlyUnread = false;

    /**
     * Cambiar un filtro vuelve a la primera página: si no, filtrar estando en
     * la página 4 deja una lista vacía que parece «no hay nada».
     *
     * Los dos hooks autorizan aunque sólo lean. No es ceremonia: `updated*` es
     * un método público que se invoca por `/livewire/update`, donde el
     * middleware de la ruta no vuelve a correr, y R23 lo trata como cualquier
     * otra puerta abierta al cliente. La comprobación es la misma que la del
     * resto del componente y cuesta una llamada al Gate.
     */
    public function updatedCategory(): void
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $this->resetPage();
    }

    public function updatedOnlyUnread(): void
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $this->resetPage();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return [
            ['value' => '', 'label' => __('Todas las categorías')],
            ...resolve(NotificationCategories::class)->options(),
        ];
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->user()->unreadNotifications()->count();
    }

    /**
     * Marca una como leída.
     *
     * El id llega por parámetro y **no** como propiedad pública: no hay estado
     * que un cliente pueda reescribir por `/livewire/update` (que es lo que R24
     * evita con `#[Locked]`), y la autorización se hace sobre la fila ya
     * cargada desde la relación del usuario — así el ámbito lo pone la
     * consulta, no una comprobación que se pueda olvidar.
     */
    public function markAsRead(string $id, NotificationMarkReadAction $action): void
    {
        $user = $this->user();

        $notification = $user->notifications()->whereKey($id)->first();

        if (! $notification instanceof DatabaseNotification) {
            return;
        }

        $this->authorize('update', $notification);

        $action->handle($user, $id);

        unset($this->unreadCount);
        $this->dispatch('notifications-updated');
    }

    public function markAllAsRead(NotificationMarkAllReadAction $action): void
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $action->handle($this->user());

        unset($this->unreadCount);
        $this->dispatch('notifications-updated');

        $this->toast()
            ->success(__('¡Listo!'), __('Marcamos tus notificaciones como leídas.'))
            ->send();
    }

    public function render(): View
    {
        $paginator = $this->paginate();

        return view('notifications::livewire.table-notifications', [
            'paginator' => $paginator,
            'notifications' => resolve(NotificationPresenter::class)->presentAll($paginator->items()),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    private function paginate(): LengthAwarePaginator
    {
        $categories = resolve(NotificationCategories::class);

        $query = $this->user()->notifications()->getQuery();

        if ($this->onlyUnread) {
            $query->whereNull('read_at');
        }

        if ($this->category !== '' && $categories->has($this->category)) {
            // El filtro pega contra el JSON del payload: la tabla es la
            // estándar de Laravel y no tiene columna de categoría. Añadirle una
            // obligaría a mantener una migración propia sobre una tabla del
            // framework, y el volumen de una bandeja personal no lo justifica.
            $query->whereJsonContains('data->category', $this->category);
        }

        /** @var LengthAwarePaginator<int, DatabaseNotification> $paginator */
        $paginator = $query->latest()->paginate(self::PER_PAGE);

        return $paginator;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
