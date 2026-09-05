<?php

declare(strict_types=1);

namespace App\Modules\Auth\Database\Factories;

use App\Core\Enums\SystemRole;
use App\Modules\Auth\Models\InvitationCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory del módulo, resuelta por la convención que registra
 * `AppServiceProvider::configureFactories()`:
 * `App\Modules\Auth\Models\InvitationCode` → `App\Modules\Auth\Database\Factories\InvitationCodeFactory`.
 *
 * @extends Factory<InvitationCode>
 */
final class InvitationCodeFactory extends Factory
{
    /** @var class-string<InvitationCode> */
    protected $model = InvitationCode::class;

    /**
     * Un código útil por defecto: sin caducidad y sin tope. Los tres estados
     * que sí bloquean tienen su propio estado nombrado, para que un test diga
     * `->expired()` en vez de calcular una fecha.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => InvitationCode::generate(),
            'role' => SystemRole::User->value,
            'max_uses' => null,
            'uses' => 0,
            'expires_at' => null,
            'created_by' => null,
            'note' => null,
        ];
    }

    /** Caducado ayer. */
    public function expired(): self
    {
        return $this->state(['expires_at' => CarbonImmutable::now()->subDay()]);
    }

    /** Con el cupo lleno. */
    public function exhausted(): self
    {
        return $this->state(['max_uses' => 3, 'uses' => 3]);
    }

    public function forRole(SystemRole $role): self
    {
        return $this->state(['role' => $role->value]);
    }
}
