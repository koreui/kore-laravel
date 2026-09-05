<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Database\Factories\NotificationPreferenceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * La preferencia de una persona para una categoría.
 *
 * Sólo existe fila cuando alguien tocó el interruptor: mientras no lo haga,
 * quien responde es el default de `kore-notifications.categories`. Ver
 * `App\Modules\Notifications\Support\NotificationPreferences`, que es el único
 * sitio que resuelve esa mezcla — ninguna pantalla consulta este modelo
 * directamente.
 *
 * `category` no lleva cast a enum a propósito: el catálogo real es el config, y
 * un derivado puede tener categorías que `App\Core\Enums\NotificationCategory`
 * no lista.
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property bool $in_app
 * @property bool $mail
 * @property bool $push
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'user_id',
    'category',
    'in_app',
    'mail',
    'push',
])]
final class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'in_app' => 'bool',
            'mail' => 'bool',
            'push' => 'bool',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
