<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Database\Factories;

use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    /** @var class-string<WebhookDelivery> */
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'auth.api_token.issued',
            'payload' => ['user' => ['id' => 1]],
            'attempts' => 0,
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => CarbonImmutable::now(),
            'delivered_at' => null,
            'last_error' => null,
            'response_status' => null,
        ];
    }

    /**
     * Entrega ya cerrada con éxito hace `$daysAgo` días: lo que
     * `webhooks:prune` se lleva pasado el plazo.
     */
    public function delivered(int $daysAgo = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryStatus::Delivered,
            'attempts' => 1,
            'next_attempt_at' => null,
            'delivered_at' => CarbonImmutable::now()->subDays($daysAgo),
            'response_status' => 200,
            'created_at' => CarbonImmutable::now()->subDays($daysAgo),
            'updated_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    /**
     * Entrega que agotó sus intentos hace `$daysAgo` días.
     */
    public function exhausted(int $daysAgo = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryStatus::Exhausted,
            'attempts' => 6,
            'next_attempt_at' => null,
            'last_error' => 'HTTP 500',
            'response_status' => 500,
            'created_at' => CarbonImmutable::now()->subDays($daysAgo),
            'updated_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    /**
     * Entrega que falló y espera su reintento dentro de `$inSeconds`.
     */
    public function retryingIn(int $inSeconds): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeliveryStatus::Failed,
            'attempts' => 1,
            'next_attempt_at' => CarbonImmutable::now()->addSeconds($inSeconds),
            'last_error' => 'HTTP 503',
            'response_status' => 503,
        ]);
    }
}
