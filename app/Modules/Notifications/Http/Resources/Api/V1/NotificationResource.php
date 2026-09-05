<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Una notificación tal como la lee la app.
 *
 * El payload sale **aplanado** y no anidado bajo `data`: para el cliente esto
 * es un aviso con título, cuerpo y destino, no una fila con una columna JSON
 * dentro. Que Laravel guarde todo en `data` es un detalle de nuestra base.
 *
 * Es una lista blanca, como `UserResource`: lo que no esté escrito aquí no
 * sale, y una clave nueva en el payload no se publica sola.
 *
 * Lo que `NotificationData` llama `data` sale aquí como **`payload`**, y el
 * cambio de nombre no es cosmético: `data` es el sobre del contrato (R54), y un
 * recurso que declara un campo con ese nombre hace que
 * `ResourceResponse::wrap()` lo confunda con el sobre ya puesto y devuelva la
 * notificación **sin envelope**, con `meta` colgando a su lado. Se descubrió
 * porque el test del contrato empezó a leer `null` en `data.read`.
 *
 * `payload` viaja a propósito: es donde el emisor mete los ids que el cliente
 * necesita para navegar (`token_id`, `device_id`). La bandeja no depende de su
 * contenido, así que un aviso sin él se pinta igual.
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends BaseApiResource
{
    /**
     * @return array{id: string, category: string, title: string, body: string, url: string|null, payload: array<string, mixed>, read: bool, read_at: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $stored */
        $stored = (array) $this->resource->getAttribute('data');

        /** @var array<string, mixed> $payload */
        $payload = is_array($stored['data'] ?? null) ? $stored['data'] : [];

        return [
            'id' => (string) $this->resource->getKey(),
            'category' => (string) ($stored['category'] ?? ''),
            'title' => (string) ($stored['title'] ?? ''),
            'body' => (string) ($stored['body'] ?? ''),
            'url' => is_string($stored['url'] ?? null) ? $stored['url'] : null,
            'payload' => $payload,
            'read' => $this->resource->read_at !== null,
            'read_at' => $this->resource->read_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
