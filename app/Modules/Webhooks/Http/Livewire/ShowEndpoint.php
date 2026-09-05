<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Livewire;

use App\Core\Concerns\RedirectsWithToast;
use App\Modules\Webhooks\Actions\WebhookDeliveryRetryAction;
use App\Modules\Webhooks\Actions\WebhookEndpointRotateSecretAction;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Builder;
use JsonException;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La pantalla de un endpoint: sus últimas entregas, el payload que se mandó y
 * lo que contestó el receptor.
 *
 * Es la pantalla que se abre cuando alguien dice «no me está llegando nada», y
 * está pensada para contestar esa pregunta sin entrar en la base: qué se
 * intentó, cuántas veces, con qué error y cuándo es el siguiente intento.
 *
 * Las filas salen del componente como **arrays ya formateados** (R30): la Blade
 * no toca Eloquent ni decide cómo se pinta una fecha.
 */
#[Layout('layouts.app')]
final class ShowEndpoint extends Component
{
    use InteractsWithFeedback;
    use RedirectsWithToast;

    /** Cuántas entregas se pintan. La tabla es un diagnóstico, no un archivo. */
    public const int LIMIT = 50;

    /**
     * `#[Locked]`: identifica sobre qué endpoint operan `retryDelivery()` y
     * `rotateSecret()`. Sin el candado sería el navegador quien lo eligiera
     * (R24).
     */
    #[Locked]
    public WebhookEndpoint $endpoint;

    /** Filtro de la tabla de entregas; vacío = todas. */
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('view', $this->endpoint);
    }

    /**
     * Reencola una entrega a mano.
     *
     * Autoriza aquí dentro y no se conforma con el `permission:` de la ruta: la
     * llamada viaja por `/livewire/update` (R23). Y comprueba que la entrega sea
     * **de este endpoint**: el id llega del cliente, así que sin ese corte
     * cualquiera con acceso a una integración podría reencolar las de otra.
     */
    public function retryDelivery(int $deliveryId, WebhookDeliveryRetryAction $retry): void
    {
        $this->authorize('update', $this->endpoint);

        $delivery = WebhookDelivery::query()
            ->where('endpoint_id', '=', $this->endpoint->id)
            ->find($deliveryId);

        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        if (! $retry->handle($delivery)) {
            $this->toast()
                ->warning(__('Nada que reintentar'), __('Esta entrega ya se había entregado.'))
                ->send();

            return;
        }

        unset($this->deliveries);

        $this->toast()
            ->success(__('¡Listo!'), __('Entrega reencolada.'))
            ->send();
    }

    /**
     * Cambia el secreto y lo enseña una vez.
     *
     * Corta en seco: desde la siguiente entrega, el receptor que siga con la
     * clave vieja rechazará las firmas. Por eso redirige en vez de repintar —el
     * secreto viaja en la sesión, no en el snapshot del componente, y así no se
     * queda en el DOM durante el resto de la visita—.
     */
    public function rotateSecret(WebhookEndpointRotateSecretAction $rotate): mixed
    {
        $this->authorize('update', $this->endpoint);

        session()->flash(FormComponent::SECRET_FLASH_KEY, $rotate->handle($this->endpoint));

        return $this->redirectWithToast(
            'webhooks.show',
            __('Secreto rotado'),
            __('Cópialo ahora: no se vuelve a mostrar.'),
            ['endpoint' => $this->endpoint->uuid],
        );
    }

    /**
     * El secreto de un solo uso, si venimos de crear o de rotar.
     *
     * Se lee de la sesión, que ya lo ha consumido: en la siguiente petición no
     * está. Eso es lo que hace que «se muestra una vez» sea verdad y no una
     * promesa de la interfaz.
     */
    #[Computed]
    public function revealedSecret(): ?string
    {
        $secret = session(FormComponent::SECRET_FLASH_KEY);

        return is_string($secret) ? $secret : null;
    }

    /**
     * Las últimas entregas, ya formateadas para la vista.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function deliveries(): array
    {
        return $this->deliveriesQuery()
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'uuid' => $delivery->uuid,
                'event' => $delivery->event,
                'status' => $delivery->status->label(),
                'color' => $delivery->status->color(),
                'open' => $delivery->status->isOpen(),
                'retryable' => $delivery->status !== DeliveryStatus::Delivered,
                'attempts' => $delivery->attempts,
                'response_status' => $delivery->response_status,
                'last_error' => $delivery->last_error,
                'payload' => $this->pretty($delivery->payload),
                'created_at' => $delivery->created_at->toDateTimeString(),
                'next_attempt_at' => $delivery->next_attempt_at?->toDateTimeString(),
                'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Cuántas entregas hay en cada estado. Es el resumen que dice de un vistazo
     * si la integración está sana sin leer cincuenta filas.
     *
     * @return array<int, array{label: string, color: string, count: int}>
     */
    #[Computed]
    public function summary(): array
    {
        $counts = WebhookDelivery::query()
            ->where('endpoint_id', '=', $this->endpoint->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(
            fn (DeliveryStatus $status): array => [
                'label' => $status->label(),
                'color' => $status->color(),
                'count' => (int) $counts->get($status->value, 0),
            ],
            DeliveryStatus::cases(),
        );
    }

    /**
     * Datos del endpoint para la cabecera, sin Eloquent en la Blade (R30).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function details(): array
    {
        return [
            'name' => $this->endpoint->name,
            'url' => $this->endpoint->url,
            'uuid' => $this->endpoint->uuid,
            'active' => $this->endpoint->active,
            'events' => $this->endpoint->subscribesTo(WebhookEndpoint::ALL_EVENTS)
                ? [__('Todos los eventos')]
                : $this->endpoint->subscribed_events,
            'created_at' => $this->endpoint->created_at->toDateTimeString(),
        ];
    }

    /**
     * Opciones del filtro de estado.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return [
            ['value' => '', 'label' => __('Todos los estados')],
            ...array_map(
                fn (DeliveryStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                DeliveryStatus::cases(),
            ),
        ];
    }

    public function render(): mixed
    {
        return view('webhooks::livewire.show-endpoint');
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveriesQuery(): Builder
    {
        $query = WebhookDelivery::query()->where('endpoint_id', '=', $this->endpoint->id);

        // El estado llega del cliente, así que se compara contra el enum en vez
        // de meterlo en el `where` tal cual.
        $status = DeliveryStatus::tryFrom($this->status);

        if ($status instanceof DeliveryStatus) {
            $query->where('status', '=', $status->value);
        }

        return $query;
    }

    /**
     * El payload, legible.
     *
     * @param array<string, mixed> $payload
     */
    private function pretty(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            // Que un payload raro no reviente la pantalla que existe justamente
            // para diagnosticar entregas raras.
            return '{}';
        }
    }
}
