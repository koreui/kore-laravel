<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Data\NotificationPreferenceData;
use App\Modules\Notifications\Models\NotificationPreference;

/**
 * Resuelve qué canales quiere una persona para una categoría.
 *
 * Mezcla dos fuentes en una sola respuesta: la fila de
 * `notification_preferences` si existe, y el default de
 * `kore-notifications.categories` si no. **La ausencia de fila no es
 * «apagado»**, y ésa es la decisión que sostiene todo lo demás: añadir una
 * categoría no obliga a sembrar filas para toda la plantilla, y un usuario
 * recién creado recibe lo que tiene que recibir sin pasar por ningún seeder.
 *
 * Se registra como `scoped()` (una instancia por petición): una misma corrida
 * puede avisar a la misma persona varias veces —un `notifyMany` con la lista de
 * responsables— y sin la caché eso son tantas consultas como avisos.
 * `forget()` la vacía cuando la pantalla de preferencias acaba de escribir.
 */
final class NotificationPreferences
{
    /** @var array<string, NotificationPreferenceData> */
    private array $cache = [];

    public function __construct(private readonly NotificationCategories $categories) {}

    /**
     * La preferencia efectiva de esta persona para esta categoría.
     */
    public function for(int $userId, string $category): NotificationPreferenceData
    {
        $key = $userId.':'.$category;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $stored = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->first();

        return $this->cache[$key] = $stored instanceof NotificationPreference
            ? $this->fromModel($stored)
            : $this->fallback($category);
    }

    /**
     * Todo el catálogo resuelto para una persona, en el orden del config.
     *
     * Es lo que pinta la pantalla de preferencias y lo que publica la API: la
     * lista completa, con los defaults ya aplicados, para que ningún cliente
     * tenga que saber que una fila ausente significa «nunca lo configuró».
     *
     * @return array<string, NotificationPreferenceData>
     */
    public function all(int $userId): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->category);

        $resolved = [];

        foreach ($this->categories->keys() as $category) {
            $model = $stored->get($category);

            $resolved[$category] = $model instanceof NotificationPreference
                ? $this->fromModel($model)
                : $this->fallback($category);

            $this->cache[$userId.':'.$category] = $resolved[$category];
        }

        return $resolved;
    }

    /** Vacía la caché: lo llama quien acaba de guardar una preferencia. */
    public function forget(): void
    {
        $this->cache = [];
    }

    private function fromModel(NotificationPreference $preference): NotificationPreferenceData
    {
        return new NotificationPreferenceData(
            category: $preference->category,
            inApp: $preference->in_app,
            mail: $preference->mail,
            push: $preference->push,
        );
    }

    private function fallback(string $category): NotificationPreferenceData
    {
        $defaults = $this->categories->defaults($category);

        return new NotificationPreferenceData(
            category: $category,
            inApp: $defaults['in_app'],
            mail: $defaults['mail'],
            push: $defaults['push'],
        );
    }
}
