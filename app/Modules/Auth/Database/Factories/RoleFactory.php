<?php

declare(strict_types=1);

namespace App\Modules\Auth\Database\Factories;

use App\Core\Enums\SystemRole;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`.
 *
 * Los roles del sistema NO se crean con esta factory: los siembra
 * `ModulesSeeder` con los nombres de {@see SystemRole}. Esto es para los roles
 * ad-hoc que necesita un test.
 *
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    /** @var class-string<Role> */
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->word()),
            'guard_name' => config('auth.defaults.guard'),
        ];
    }

    /**
     * Uno de los roles que el boilerplate trae de serie.
     */
    public function system(SystemRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $role->value,
        ]);
    }
}
