<?php

declare(strict_types=1);

namespace App\Modules\Mx\Database\Factories;

use App\Modules\Mx\Models\PostalCode;
use App\Modules\Mx\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory del módulo (misma convención que `StateFactory`).
 *
 * `state_code` se resuelve creando la entidad si no existe: la FK apunta a
 * `mx_states.code`, así que un asentamiento sin su entidad no se puede insertar
 * y el test fallaría con un error de integridad en vez de con el suyo.
 *
 * @extends Factory<PostalCode>
 */
final class PostalCodeFactory extends Factory
{
    /** @var class-string<PostalCode> */
    protected $model = PostalCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'postal_code' => (string) fake()->numerify('#####'),
            'settlement' => fake()->streetName(),
            'settlement_type' => 'Colonia',
            'municipality' => fake()->city(),
            'city' => null,
            'state_code' => fn (): string => (string) State::query()
                ->firstOrCreate(['code' => '09'], ['name' => 'Ciudad de México', 'abbreviation' => 'CDMX'])
                ->code,
        ];
    }
}
