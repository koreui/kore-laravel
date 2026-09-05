<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Database\Factories;

use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\Webhooks\Models\WebhookEndpoint` →
 * `App\Modules\Webhooks\Database\Factories\WebhookEndpointFactory`.
 *
 * @extends Factory<WebhookEndpoint>
 */
final class WebhookEndpointFactory extends Factory
{
    /** @var class-string<WebhookEndpoint> */
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://'.fake()->domainName().'/webhooks/kore',
            'secret' => Str::random(48),
            'subscribed_events' => [WebhookEndpoint::ALL_EVENTS],
            'active' => true,
            'created_by' => null,
        ];
    }

    /**
     * Endpoint apagado: sigue en la lista, pero el publisher no le escribe.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    /**
     * Endpoint suscrito sólo a estos eventos.
     *
     * @param array<int, string> $events
     */
    public function subscribedTo(array $events): static
    {
        return $this->state(fn (array $attributes): array => [
            'subscribed_events' => $events,
        ]);
    }
}
