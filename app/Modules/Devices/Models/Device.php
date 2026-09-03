<?php

declare(strict_types=1);

namespace App\Modules\Devices\Models;

use App\Core\Concerns\HasPublicUuid;
use App\Models\User;
use App\Modules\Devices\Database\Factories\DeviceFactory;
use App\Modules\Devices\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use Override;

/**
 * Un cliente que consume la API con un token de Sanctum: una app móvil, el
 * navegador de un SPA, un CLI o un servidor.
 *
 * El registro **no lo hace este módulo**: lo dispara Auth al emitir un token
 * (`ApiTokenIssued`) y lo escucha `RegisterDeviceOnTokenIssued`. Devices sólo
 * mantiene el inventario y lo publica.
 *
 * Tres decisiones que no son detalles:
 *
 * - **`uuid` como identidad pública** (`HasPublicUuid` + `ROUTE_BY_UUID`). La
 *   llave primaria sigue siendo el entero —barato para el índice y para la FK
 *   contra `personal_access_tokens`—, pero la API expone el uuid: un
 *   `DELETE /api/v1/devices/7` diría cuántos dispositivos hay en la instalación
 *   y haría que probar el 8 fuera gratis.
 * - **`push_token` es `#[Hidden]`.** Es una credencial de envío: quien la tiene
 *   puede mandar notificaciones a ese teléfono. No sale por la API ni por un
 *   `toArray()` accidental, y `DeviceResource` tampoco lo publica. Dos barreras
 *   para el mismo dato, a propósito.
 * - **`user_id` y `device_id` son únicos juntos.** El `device_id` lo elige el
 *   cliente y sólo tiene que ser estable para él; dos usuarios en el mismo
 *   teléfono son dos filas, y volver a entrar con la misma cuenta reutiliza la
 *   suya en vez de acumular una por sesión.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $device_id
 * @property string|null $name
 * @property Platform|null $platform
 * @property string|null $app_version
 * @property string|null $push_token
 * @property int|null $access_token_id
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'user_id',
    'device_id',
    'name',
    'platform',
    'app_version',
    'push_token',
    'access_token_id',
    'last_seen_at',
    'revoked_at',
])]
#[Hidden([
    'push_token',
])]
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** La API enruta por `uuid`, nunca por el id entero. */
    public const bool ROUTE_BY_UUID = true;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El token de Sanctum con el que este dispositivo habla con la API.
     *
     * Nullable y con `nullOnDelete`: el token puede desaparecer sin el
     * dispositivo (lo purga `sanctum:prune-expired`, o el usuario cierra sesión
     * desde otro sitio) y la fila del inventario sobrevive para poder decir
     * «este dispositivo estuvo aquí».
     *
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'access_token_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Los que todavía valen: sin fecha de revocación.
     *
     * `protected` porque Larastan lo exige para los scopes de Laravel 13
     * (`NoPublicModelScopeAndAccessorRule`); se sigue llamando como
     * `Device::query()->active()`.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
