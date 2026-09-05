<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Traduce una fila de `notifications` a lo que una Blade puede pintar.
 *
 * Existe porque R30 no admite Eloquent en la vista y porque la campana y la
 * bandeja pintan **lo mismo**: título, cuerpo, etiqueta de la categoría, el
 * enlace si lo hay y un «hace tres horas». Sin esta clase el mapeo estaría dos
 * veces y se separaría a la primera.
 *
 * Todos los accesos van por `getAttribute()`: `DatabaseNotification` es un
 * modelo del framework sin `@property`, así que sus columnas no existen para el
 * análisis estático. Es el precio de usar la tabla estándar, y sale a cuenta —
 * lo que se compra con ella es que `markAsRead()` y `unreadNotifications()`
 * funcionen sin una línea de código propio.
 */
final readonly class NotificationPresenter
{
    public function __construct(private NotificationCategories $categories) {}

    /**
     * @return array{id: string, title: string, body: string, category: string, url: string|null, unread: bool, when: string}
     */
    public function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $notification->getAttribute('data');

        $url = $payload['url'] ?? null;
        $createdAt = $notification->getAttribute('created_at');

        return [
            'id' => (string) $notification->getKey(),
            'title' => (string) ($payload['title'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'category' => $this->categories->label((string) ($payload['category'] ?? '')),
            'url' => is_string($url) && $url !== '' ? $url : null,
            'unread' => $notification->getAttribute('read_at') === null,
            'when' => $createdAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($createdAt)->diffForHumans()
                : '',
        ];
    }

    /**
     * @param iterable<int, DatabaseNotification> $notifications
     * @return array<int, array{id: string, title: string, body: string, category: string, url: string|null, unread: bool, when: string}>
     */
    public function presentAll(iterable $notifications): array
    {
        $presented = [];

        foreach ($notifications as $notification) {
            $presented[] = $this->present($notification);
        }

        return $presented;
    }
}
