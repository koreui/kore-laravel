<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Forms;

use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Livewire Form Object del alta y la edición de un endpoint.
 *
 * El form NO persiste: valida y traduce. Escribir es trabajo de
 * `WebhookEndpointCreateAction` / `WebhookEndpointUpdateAction` (R4).
 *
 * **`$id` va con `#[Locked]`** (R24). Sin ese candado, un cliente con permiso
 * para crear podría mandar `form.id` por `/livewire/update` y reescribir
 * cualquier otro endpoint: su URL, es decir, a dónde se manda todo lo que pasa
 * en esta instalación. El candado sólo bloquea escrituras del cliente; el
 * `mount()` del componente sigue pudiendo asignarlo con `fill()`.
 *
 * **El secreto no está aquí.** Lo genera la Action y sólo se enseña una vez; un
 * formulario que lo aceptara sería un formulario que acepta la entropía que al
 * suscriptor le apetezca.
 */
final class WebhookEndpointForm extends Form
{
    #[Locked]
    public ?int $id = null;

    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $events = [];

    public bool $active = true;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', $this->urlRule(), 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($this->selectableEvents())],
            'active' => ['boolean'],
        ];
    }

    /**
     * Los valores que el selector puede mandar: el catálogo más el comodín.
     *
     * @return array<int, string>
     */
    public function selectableEvents(): array
    {
        /** @var array<string, string> $catalog */
        $catalog = (array) config('kore-webhooks.events', []);

        return [WebhookEndpoint::ALL_EVENTS, ...array_keys($catalog)];
    }

    /**
     * `https` obligatorio salvo en `local`.
     *
     * La firma protege la integridad del mensaje, no su confidencialidad: por
     * `http` el payload viaja legible para cualquiera en el camino, y lo que
     * viaja son datos del dominio. La excepción de `local` existe porque el
     * receptor de al lado suele ser un `php artisan serve` sin certificado, y
     * obligar a montar TLS para probar una integración sólo consigue que se
     * pruebe en producción.
     */
    private function urlRule(): string
    {
        $requireHttps = (bool) config('kore-webhooks.require_https', true)
            && ! app()->environment('local');

        return $requireHttps ? 'url:https' : 'url:http,https';
    }

    /**
     * Estado del formulario como DTO para las Actions.
     *
     * Llamar SIEMPRE después de `validate()`: `WebhookEndpointData` no valida
     * nada.
     */
    public function toData(): WebhookEndpointData
    {
        return new WebhookEndpointData(
            name: $this->name,
            url: $this->url,
            events: array_values($this->events),
            active: $this->active,
        );
    }
}
