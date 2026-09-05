<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Database\Factories;

use App\Core\Enums\NotificationCategory;
use App\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\Notifications\Models\NotificationPreference` →
 * `App\Modules\Notifications\Database\Factories\NotificationPreferenceFactory`.
 *
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    /** @var class-string<NotificationPreference> */
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(NotificationCategory::cases())->value,
            'in_app' => true,
            'mail' => true,
            'push' => false,
        ];
    }

    /**
     * Todos los canales apagados: quien pidió que no le avisen de esta área.
     */
    public function silenced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'in_app' => false,
            'mail' => false,
            'push' => false,
        ]);
    }

    public function forCategory(string $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }
}
