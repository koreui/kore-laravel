<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database\Factories;

use App\Modules\Platform\Models\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
final class NumberSequenceFactory extends Factory
{
    /** @var class-string<NumberSequence> */
    protected $model = NumberSequence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'series' => 'receipt',
            'scope' => null,
            'period' => (string) now()->year,
            'last_number' => 0,
        ];
    }

    /**
     * Un contador que ya va por un número: sirve para probar que la serie
     * continúa donde estaba en vez de empezar de cero.
     */
    public function at(int $lastNumber): self
    {
        return $this->state(fn (): array => ['last_number' => $lastNumber]);
    }
}
