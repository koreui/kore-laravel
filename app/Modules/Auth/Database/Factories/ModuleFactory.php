<?php

declare(strict_types=1);

namespace App\Modules\Auth\Database\Factories;

use App\Core\Enums\SystemRole;
use App\Modules\Auth\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\{Mod}\Models\{X}` → `App\Modules\{Mod}\Database\Factories\{X}Factory`.
 *
 * @extends Factory<Module>
 */
final class ModuleFactory extends Factory
{
    /** @var class-string<Module> */
    protected $model = Module::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'roles' => [SystemRole::Admin->value],
            'active' => true,
        ];
    }

    /**
     * Módulo desactivado: no genera permisos ni aparece en el catálogo.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
