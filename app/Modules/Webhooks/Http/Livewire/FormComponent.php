<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Livewire;

use App\Core\Concerns\RedirectsWithToast;
use App\Modules\Webhooks\Actions\WebhookEndpointCreateAction;
use App\Modules\Webhooks\Actions\WebhookEndpointUpdateAction;
use App\Modules\Webhooks\Forms\WebhookEndpointForm;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Alta y edición de un endpoint.
 *
 * Autoriza → valida → DTO → Action, y nada más: la escritura vive en la Action.
 *
 * Al **crear**, el secreto recién generado se deja en sesión y la pantalla de
 * detalle lo enseña una sola vez. Va por sesión y no como propiedad del
 * componente a propósito: una propiedad pública viaja en el snapshot de
 * Livewire en cada petición siguiente, así que el secreto se quedaría en el DOM
 * y en el historial de la pestaña mucho después de que alguien lo copiara.
 */
#[Layout('layouts.app')]
final class FormComponent extends Component
{
    use InteractsWithFeedback;
    use RedirectsWithToast;

    /** Clave del secreto de un solo uso en la sesión. */
    public const string SECRET_FLASH_KEY = 'webhooks.secret';

    #[Locked]
    public ?WebhookEndpoint $model = null;

    public WebhookEndpointForm $form;

    public function mount(): void
    {
        if (! $this->model instanceof WebhookEndpoint) {
            $this->authorize('create', WebhookEndpoint::class);

            return;
        }

        $this->authorize('update', $this->model);

        $this->form->fill([
            'id' => $this->model->id,
            'name' => $this->model->name,
            'url' => $this->model->url,
            'events' => $this->model->subscribed_events,
            'active' => $this->model->active,
        ]);
    }

    /**
     * Las rutas de `webhooks` llevan `permission:webhooks.manage`, pero las
     * llamadas de Livewire viajan por `/livewire/update`, donde ese middleware
     * NO corre. Por eso la autorización tiene que estar aquí dentro (R23).
     */
    public function save(
        WebhookEndpointCreateAction $createEndpoint,
        WebhookEndpointUpdateAction $updateEndpoint,
    ): mixed {
        if ($this->model instanceof WebhookEndpoint) {
            $this->authorize('update', $this->model);
        } else {
            $this->authorize('create', WebhookEndpoint::class);
        }

        $this->form->validate();

        $data = $this->form->toData();

        if ($this->model instanceof WebhookEndpoint) {
            $endpoint = $updateEndpoint->handle($this->model, $data);
        } else {
            $endpoint = $createEndpoint->handle($data, (int) auth()->id());

            // Una sola vez, y en sesión: ver el docblock de la clase.
            session()->flash(self::SECRET_FLASH_KEY, $endpoint->secret);
        }

        $this->form->id = $endpoint->id;

        return $this->redirectWithToast(
            'webhooks.show',
            __('¡Listo!'),
            __('Endpoint guardado correctamente.'),
            ['endpoint' => $endpoint->uuid],
        );
    }

    #[Computed]
    public function title(): string
    {
        return $this->model instanceof WebhookEndpoint ? __('Editar endpoint') : __('Crear endpoint');
    }

    /**
     * El catálogo de eventos para las casillas, ya serializado.
     *
     * Sale del componente como array de arrays y no como config cruda (R30):
     * la Blade recibe datos preparados. El comodín va el primero y con su
     * propia explicación, porque es el que cambia de significado con el tiempo.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function eventOptions(): array
    {
        /** @var array<string, string> $catalog */
        $catalog = (array) config('kore-webhooks.events', []);

        $options = [[
            'value' => WebhookEndpoint::ALL_EVENTS,
            'label' => __('Todos los eventos, incluidos los que se añadan más adelante'),
        ]];

        foreach ($catalog as $name => $description) {
            $options[] = ['value' => $name, 'label' => $name.' — '.$description];
        }

        return $options;
    }

    public function render(): mixed
    {
        return view('webhooks::livewire.form-component');
    }
}
