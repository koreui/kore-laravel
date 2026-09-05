<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Livewire;

use App\Models\User;
use App\Modules\Notifications\Actions\NotificationPreferenceUpdateAction;
use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationCategories;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Los interruptores de la propia cuenta: categoría × canal.
 *
 * Una fila por categoría del catálogo (`kore-notifications.categories`), así
 * que un derivado que añade la suya la ve aparecer aquí sin tocar la pantalla.
 *
 * `$preferences` es un array plano `['system' => ['in_app' => true, ...]]` y no
 * una colección de modelos: es lo que un `wire:model` puede escribir, y lo que
 * evita que la Blade toque Eloquent (R30). Lo que llega del cliente se
 * **reconstruye** al guardar contra el catálogo real —clave a clave, casteando
 * a booleano—, así que una categoría inventada en el payload no crea ninguna
 * fila.
 */
final class NotificationSettings extends Component
{
    use InteractsWithFeedback;

    /**
     * El estado de los interruptores.
     *
     * @var array<string, array{in_app: bool, mail: bool, push: bool}>
     */
    public array $preferences = [];

    public function mount(NotificationPreferences $preferences): void
    {
        foreach ($preferences->all((int) $this->user()->getKey()) as $category => $preference) {
            $this->preferences[$category] = [
                'in_app' => $preference->inApp,
                'mail' => $preference->mail,
                'push' => $preference->push,
            ];
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function categories(): array
    {
        return resolve(NotificationCategories::class)->options();
    }

    /**
     * ¿Tiene sentido enseñar el interruptor de push?
     *
     * Sólo con el módulo Devices encendido: sin inventario de dispositivos no
     * hay a dónde mandar un push, y ofrecer un interruptor que no hace nada es
     * prometer algo que no ocurre. El aviso sigue llegando a la bandeja.
     */
    #[Computed]
    public function pushAvailable(): bool
    {
        return (bool) config('kore-app.devices.enabled', false);
    }

    /**
     * Guarda las preferencias, una fila por categoría.
     *
     * Autoriza **cada** fila contra la Policy y no una vez al entrar: la que
     * decide es `NotificationPreferencePolicy::update()`, que compara el
     * `user_id` de la fila con el de la sesión. Al recorrer el catálogo del
     * servidor —y no las claves que mandó el cliente— una categoría inventada
     * en el payload no llega ni a construirse.
     */
    public function save(NotificationPreferenceUpdateAction $action): void
    {
        $user = $this->user();

        foreach (resolve(NotificationCategories::class)->keys() as $category) {
            $values = $this->preferences[$category] ?? null;

            if (! is_array($values)) {
                continue;
            }

            $this->authorize('update', new NotificationPreference([
                'user_id' => $user->getKey(),
                'category' => $category,
            ]));

            $action->handle($user, new NotificationPreferenceData(
                category: $category,
                inApp: (bool) ($values['in_app'] ?? false),
                mail: (bool) ($values['mail'] ?? false),
                push: (bool) ($values['push'] ?? false),
            ));
        }

        $this->dispatch('notifications-updated');

        $this->toast()
            ->success(__('¡Listo!'), __('Guardamos tus preferencias.'))
            ->send();
    }

    public function render(): View
    {
        return view('notifications::livewire.notification-settings');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
