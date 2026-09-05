<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Livewire;

use App\Models\User;
use App\Modules\Notifications\Actions\NotificationMarkAllReadAction;
use App\Modules\Notifications\Support\NotificationPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * La campana del encabezado: cuántas hay sin leer y las cinco últimas.
 *
 * Cinco y no todas porque el 90 % de las veces con eso basta; para el resto
 * está `/notifications`. Se refresca con `wire:poll` cada
 * `kore-notifications.bell.poll_seconds`, y ese número está en el config
 * precisamente porque no es gratis: son tantas consultas por minuto como
 * pestañas abiertas haya. Un derivado con websockets lo pone a cero y refresca
 * por evento (`notifications-updated`, que ya se escucha aquí).
 *
 * **Lo que llega a la Blade son arrays, no modelos** (R30): el título, el
 * cuerpo, la etiqueta de la categoría y el «hace 3 horas» salen resueltos desde
 * aquí. La vista pinta; no consulta ni formatea.
 */
final class NotificationBell extends Component
{
    /** Cuántas veces se pinta el globo antes de pasar a «9+». */
    private const int BADGE_MAX = 9;

    /** Cuántas caben en el desplegable. */
    private const int PREVIEW = 5;

    #[Computed]
    public function unreadCount(): int
    {
        return $this->user()->unreadNotifications()->count();
    }

    /** El texto del globo: el número, o «9+» cuando ya no cabe. */
    #[Computed]
    public function badge(): string
    {
        $count = $this->unreadCount();

        return $count > self::BADGE_MAX ? self::BADGE_MAX.'+' : (string) $count;
    }

    /**
     * Las últimas notificaciones, ya aplanadas para la vista.
     *
     * @return array<int, array{id: string, title: string, body: string, category: string, url: string|null, unread: bool, when: string}>
     */
    #[Computed]
    public function latest(): array
    {
        /** @var iterable<int, DatabaseNotification> $notifications */
        $notifications = $this->user()
            ->notifications()
            ->latest()
            ->limit(self::PREVIEW)
            ->get();

        return resolve(NotificationPresenter::class)->presentAll($notifications);
    }

    /** Cada cuántos segundos vuelve a preguntar. Cero apaga el polling. */
    #[Computed]
    public function pollSeconds(): int
    {
        $seconds = config('kore-notifications.bell.poll_seconds', 30);

        return max(0, is_numeric($seconds) ? (int) $seconds : 30);
    }

    /**
     * Otra pantalla tocó la bandeja: tira las computadas y vuelve a contar.
     */
    #[On('notifications-updated')]
    public function refreshInbox(): void
    {
        unset($this->unreadCount, $this->badge, $this->latest);
    }

    /**
     * Marca todas como leídas.
     *
     * Autoriza aunque el nombre no empiece por un verbo de escritura de los que
     * vigila R23: la llamada viaja por `/livewire/update`, donde el middleware
     * `auth` de la ruta que pintó la campana no vuelve a correr.
     */
    public function markAllAsRead(NotificationMarkAllReadAction $action): void
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $action->handle($this->user());

        $this->refreshInbox();
        $this->dispatch('notifications-updated');
    }

    public function render(): View
    {
        return view('notifications::livewire.notification-bell');
    }

    /**
     * El usuario de la sesión.
     *
     * `abort_unless` y no un `?User`: la campana sólo se pinta dentro del
     * layout autenticado, así que llegar aquí sin sesión no es un caso de uso
     * sino una petición fabricada contra `/livewire/update`.
     */
    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
