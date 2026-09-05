<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Modules\Auth\Database\Factories\InvitationCodeFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Override;

/**
 * Un código con el que alguien puede registrarse mientras `AUTH_INVITATIONS`
 * esté encendido.
 *
 * Tres decisiones que no son detalles:
 *
 * - **El código se normaliza siempre** ({@see normalize()}): mayúsculas y sin
 *   espacios. Se guarda así y se busca así, porque quien lo teclea desde un
 *   móvil no debe fallar por un cambio de caja o por un espacio pegado al
 *   pegar.
 * - **No hay booleano `activo`.** Revocar es adelantar `expires_at` a ahora.
 *   Un estado menos que mantener, una fecha más que auditar, y una sola
 *   pregunta —`isUsable()`— en vez de dos que pueden contradecirse.
 * - **`uses` vive en la fila** y no se cuenta con un `hasMany` sobre `users`:
 *   así el alta puede incrementarlo dentro de su propia transacción, con la
 *   fila bloqueada, y dos registros simultáneos no se cuelan por encima de
 *   `max_uses`.
 *
 * @property int $id
 * @property string $code
 * @property string $role
 * @property int|null $max_uses
 * @property int $uses
 * @property CarbonImmutable|null $expires_at
 * @property int|null $created_by
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'code',
    'role',
    'max_uses',
    'uses',
    'expires_at',
    'created_by',
    'note',
])]
final class InvitationCode extends Model
{
    /** @use HasFactory<InvitationCodeFactory> */
    use HasFactory;

    /**
     * Cuántos caracteres tiene un código generado.
     *
     * Ocho de `Str::random()` en mayúsculas son ~40 bits: suficientes para que
     * adivinar uno no sea una estrategia, y cortos para dictarlos por teléfono.
     */
    public const int GENERATED_LENGTH = 8;

    /**
     * Normaliza lo que teclea la gente: mayúsculas, sin espacios (tampoco los
     * de en medio, que es lo que deja pegado un copiar/pegar desde un chat).
     *
     * «kore 2026» y «KORE2026» son el mismo código.
     */
    public static function normalize(string $code): string
    {
        return Str::upper((string) preg_replace('/\s+/', '', trim($code)));
    }

    /**
     * Un código nuevo, ya normalizado.
     *
     * No comprueba unicidad: de eso se encarga el índice de la tabla y el
     * reintento de `InvitationCreateAction`, que es donde hay una transacción.
     */
    public static function generate(): string
    {
        return Str::upper(Str::random(self::GENERATED_LENGTH));
    }

    /**
     * Busca por código, normalizando primero. No juzga si sirve: eso lo
     * responde {@see isUsable()}.
     */
    public static function findByCode(string $code): ?self
    {
        $normalized = self::normalize($code);

        if ($normalized === '') {
            return null;
        }

        return self::query()->where('code', '=', $normalized)->first();
    }

    /**
     * Quién lo creó. Nulo si esa cuenta ya no existe.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonImmutable && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isExhausted();
    }

    /**
     * Por qué no sirve, en el orden en que le importa a quien lo teclea.
     * `null` si sí sirve.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->isExpired() => (string) __('Este código de invitación ya caducó.'),
            $this->isExhausted() => (string) __('Este código de invitación ya alcanzó su límite de registros.'),
            default => null,
        };
    }

    /** Etiqueta corta del estado, para la columna de la tabla. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isExpired() => (string) __('Caducado'),
            $this->isExhausted() => (string) __('Agotado'),
            default => (string) __('Disponible'),
        };
    }

    /** Cuántos registros lleva, para pintar «12/50» o «12/sin límite». */
    public function usageLabel(): string
    {
        return $this->max_uses === null
            ? sprintf('%d / %s', $this->uses, __('sin límite'))
            : sprintf('%d / %d', $this->uses, $this->max_uses);
    }

    /**
     * Los que todavía aceptan un registro.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->where(fn (Builder $q): Builder => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
