<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Core\Concerns\HasPublicUuid;
use App\Models\User;
use App\Modules\Webhooks\Database\Factories\WebhookEndpointFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * Un suscriptor: la URL de un tercero que quiere enterarse de ciertos eventos.
 *
 * Tres decisiones que no son detalles:
 *
 * - **El secreto va cifrado en reposo** (`encrypted`). Es la credencial con la
 *   que se firma cada entrega: quien la tiene puede fabricar un webhook que el
 *   receptor dará por bueno. Cifrarlo no protege de quien tiene la aplicación
 *   entera, pero sí de un dump de la base, de una copia de seguridad y del
 *   `toArray()` de un log. Además es `#[Hidden]`, así que no sale por accidente
 *   en ninguna serialización: dos barreras para el mismo dato, a propósito.
 * - **`uuid` como identidad pública** (`HasPublicUuid` + `ROUTE_BY_UUID`). La
 *   llave primaria sigue siendo el entero —barato para la FK de las entregas—,
 *   pero la pantalla enruta por uuid: un `/webhooks/7` diría cuántas
 *   integraciones hay y haría que probar el 8 fuera gratis.
 * - **`events` es una lista, y `*` significa «todos»**. Un endpoint que se
 *   suscribe a `*` recibe también los eventos que se añadan al catálogo
 *   después, que es lo que quiere un panel central; uno que enumera sus
 *   eventos no se entera de los nuevos, que es lo que quiere una integración
 *   concreta.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property array<int, string> $subscribed_events
 * @property bool $active
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'name',
    'url',
    'secret',
    'subscribed_events',
    'active',
    'created_by',
])]
#[Hidden([
    'secret',
])]
final class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** Comodín de `events`: este endpoint escucha todo el catálogo. */
    public const string ALL_EVENTS = '*';

    /** La pantalla enruta por `uuid`, nunca por el id entero. */
    public const bool ROUTE_BY_UUID = true;

    /**
     * Quién dio de alta la integración. Nullable porque la fila sobrevive al
     * usuario que la creó.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    /**
     * ¿Este endpoint quiere enterarse de este evento?
     */
    public function subscribesTo(string $event): bool
    {
        return in_array(self::ALL_EVENTS, $this->subscribed_events, true)
            || in_array($event, $this->subscribed_events, true);
    }

    /**
     * Los que están encendidos: los únicos a los que el publisher escribe.
     *
     * `protected` porque Larastan lo exige para los scopes de Laravel 13
     * (`NoPublicModelScopeAndAccessorRule`); se sigue llamando como
     * `WebhookEndpoint::query()->active()`.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', '=', true);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'subscribed_events' => 'array',
            'active' => 'bool',
        ];
    }
}
