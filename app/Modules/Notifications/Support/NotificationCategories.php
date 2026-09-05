<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

/**
 * El catálogo de categorías, leído de `config/kore-notifications.php`.
 *
 * Es el único sitio del módulo que abre ese config: la pantalla de
 * preferencias, la API, el filtro de la bandeja y las preferencias efectivas
 * preguntan aquí. Así, cuando un derivado añade `'facturacion' => [...]`, no
 * hay ningún otro archivo que actualizar.
 *
 * Una categoría desconocida no revienta nada: `label()` devuelve la clave y
 * `defaults()` devuelve el mismo valor conservador para todas —bandeja sí,
 * correo y push no—. Un aviso con una categoría mal escrita tiene que llegar
 * igual a la bandeja; perderlo por un typo sería peor que enseñarlo sin
 * etiqueta bonita.
 */
final class NotificationCategories
{
    /** Lo que responde `defaults()` para una categoría que no está en el config. */
    private const array UNKNOWN_DEFAULTS = ['in_app' => true, 'mail' => false, 'push' => false];

    /**
     * Las claves del catálogo, en el orden en que están escritas en el config.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->catalog()));
    }

    public function has(string $category): bool
    {
        return array_key_exists($category, $this->catalog());
    }

    /**
     * La etiqueta traducida. La fuente es española (R33) y vive en el config;
     * el `__()` la busca en el `en.json` del módulo cuando toca.
     */
    public function label(string $category): string
    {
        $label = $this->catalog()[$category]['label'] ?? null;

        return is_string($label) && $label !== '' ? __($label) : $category;
    }

    /**
     * Los canales que trae una categoría para quien nunca configuró nada.
     *
     * @return array{in_app: bool, mail: bool, push: bool}
     */
    public function defaults(string $category): array
    {
        $entry = $this->catalog()[$category] ?? null;

        if (! is_array($entry)) {
            return self::UNKNOWN_DEFAULTS;
        }

        return [
            'in_app' => (bool) ($entry['in_app'] ?? self::UNKNOWN_DEFAULTS['in_app']),
            'mail' => (bool) ($entry['mail'] ?? self::UNKNOWN_DEFAULTS['mail']),
            'push' => (bool) ($entry['push'] ?? self::UNKNOWN_DEFAULTS['push']),
        ];
    }

    /**
     * El catálogo tal como lo pinta un `<x-kore::select>` o lo publica la API.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            fn (string $category): array => ['value' => $category, 'label' => $this->label($category)],
            $this->keys(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        /** @var array<string, array<string, mixed>> $categories */
        $categories = array_filter(
            (array) config('kore-notifications.categories', []),
            is_array(...),
        );

        return $categories;
    }
}
