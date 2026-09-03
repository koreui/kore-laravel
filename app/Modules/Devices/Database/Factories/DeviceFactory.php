<?php

declare(strict_types=1);

namespace App\Modules\Devices\Database\Factories;

use App\Models\User;
use App\Modules\Devices\Enums\Platform;
use App\Modules\Devices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\Devices\Models\Device` → `App\Modules\Devices\Database\Factories\DeviceFactory`.
 *
 * @extends Factory<Device>
 */
final class DeviceFactory extends Factory
{
    /** @var class-string<Device> */
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(Platform::cases());

        return [
            'user_id' => User::factory(),
            'device_id' => Str::uuid()->toString(),
            'name' => fake()->words(2, true),
            'platform' => $platform,
            'app_version' => fake()->numerify('#.#.#'),
            'push_token' => $platform->supportsPush() ? Str::random(64) : null,
            'access_token_id' => null,
            'last_seen_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ];
    }

    /**
     * Dispositivo ya revocado hace `$daysAgo` días: lo que `devices:cleanup`
     * purga pasado el plazo de retención.
     */
    public function revoked(int $daysAgo = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    /**
     * Dispositivo vivo pero callado desde hace `$daysAgo` días: lo que
     * `devices:cleanup` revoca por abandono.
     */
    public function lastSeenDaysAgo(int $daysAgo): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_seen_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }
}
