<?php

declare(strict_types=1);

namespace App\Modules\Mx\Database\Factories;

use App\Modules\Mx\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\Mx\Models\State` → `App\Modules\Mx\Database\Factories\StateFactory`.
 *
 * La entidad por defecto es la 09 (Ciudad de México) y no una aleatoria: el
 * catálogo real son 32 filas fijas, así que un `code` inventado por Faker
 * chocaría con el índice único en cuanto un test creara dos. Quien necesite otra
 * la pide por estado (`->jalisco()`) o pasa el `code` a mano.
 *
 * @extends Factory<State>
 */
final class StateFactory extends Factory
{
    /** @var class-string<State> */
    protected $model = State::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => '09',
            'name' => 'Ciudad de México',
            'abbreviation' => 'CDMX',
        ];
    }

    /**
     * Jalisco (14), la segunda entidad más usada en los tests del módulo.
     */
    public function jalisco(): self
    {
        return $this->state(fn (): array => [
            'code' => '14',
            'name' => 'Jalisco',
            'abbreviation' => 'JAL',
        ]);
    }
}
