<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database\Factories;

use App\Modules\Platform\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    /** @var class-string<Setting> */
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'organization.name',
            'value' => fake()->company(),
            'changed_by' => null,
        ];
    }

    /**
     * Una clave concreta con su valor, que es como se usa el 99 % de las veces.
     */
    public function forKey(string $key, mixed $value): self
    {
        return $this->state(fn (): array => ['key' => $key, 'value' => $value]);
    }
}
