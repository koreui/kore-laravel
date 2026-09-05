<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Se escriben aunque coincidan con el default de la columna: `create()`
            // no relee la fila, y con `Model::shouldBeStrict()` encendido leer un
            // atributo que no se cargó lanza. Sin esto, cualquier test que pase un
            // usuario de factory por `EnsureAccountIsActive` moría con «attribute
            // does not exist» en vez de con lo que estuviera probando.
            'account_status' => AccountStatus::Active,
            'activated_at' => now(),
        ];
    }

    /** Registrada pero todavía sin activar: la ve la pantalla de espera. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_status' => AccountStatus::Pending,
            'activated_at' => null,
        ]);
    }

    /** Con el acceso cerrado a mano. */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_status' => AccountStatus::Suspended,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
