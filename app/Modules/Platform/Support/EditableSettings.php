<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use InvalidArgumentException;

/**
 * El catálogo de `config('kore-settings.editable')`, leído una sola vez y en un
 * sitio.
 *
 * Cuatro piezas necesitan lo mismo —qué claves se pueden cambiar, de qué tipo
 * son y cómo se llaman en español—: el Form Object (reglas y controles), la
 * Action (rechazar una clave no declarada), el componente Livewire (pintar el
 * campo) y `settings:show`. Repartir esa lectura entre las cuatro es cómo
 * acaban divergiendo: una añade un tipo y las otras tres no lo conocen.
 *
 * Las reglas se derivan del `type` y no se escriben clave a clave a propósito.
 * Un derivado que añade `organization.website` pone tres líneas en el config y
 * no toca ni una de PHP; si además necesita una regla propia, la pone en
 * `rules` de la entrada y ésa manda entera.
 */
final class EditableSettings
{
    /**
     * Reglas por tipo. El primer elemento es el que `required` reemplaza.
     *
     * @var array<string, array<int, string>>
     */
    private const array RULES_BY_TYPE = [
        'string' => ['nullable', 'string', 'max:255'],
        'text' => ['nullable', 'string', 'max:2000'],
        'email' => ['nullable', 'email', 'max:255'],
        'bool' => ['boolean'],
        'int' => ['nullable', 'integer'],
    ];

    /**
     * Todas las claves editables, normalizadas.
     *
     * @return array<string, array{type: string, label: string, required: bool, rules: array<int, mixed>, slug: string}>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) config('kore-settings.editable', []);

        $definitions = [];

        foreach ($raw as $key => $definition) {
            $definitions[(string) $key] = $this->normalize((string) $key, (array) $definition);
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Las mismas definiciones, indexadas por su `slug`.
     *
     * El estado del formulario se guarda por slug y no por clave porque el
     * punto es el separador de niveles tanto de Livewire (`wire:model` hace
     * `data_set`) como del validador: un `values.organization.name` acabaría
     * siendo `values['organization']['name']` en los dos, y el ajuste
     * `organization.name` dejaría de existir por el camino. El slug cambia el
     * punto por un guion bajo y la traducción de vuelta pasa por aquí.
     *
     * @return array<string, array{key: string, type: string, label: string, required: bool, rules: array<int, mixed>, slug: string}>
     */
    public function bySlug(): array
    {
        $bySlug = [];

        foreach ($this->all() as $key => $definition) {
            $slug = $definition['slug'];

            if (array_key_exists($slug, $bySlug)) {
                throw new InvalidArgumentException(
                    "Los ajustes [{$bySlug[$slug]['key']}] y [{$key}] comparten el mismo identificador de formulario "
                    ."[{$slug}]: cambia el nombre de uno de los dos en config/kore-settings.php."
                );
            }

            $bySlug[$slug] = ['key' => $key, ...$definition];
        }

        return $bySlug;
    }

    /**
     * Definición de una clave.
     *
     * @return array{type: string, label: string, required: bool, rules: array<int, mixed>, slug: string}
     *
     * @throws InvalidArgumentException si la clave no está declarada como editable
     */
    public function get(string $key): array
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException(
                "El ajuste [{$key}] no está declarado en config/kore-settings.php → editable, así que no se puede cambiar desde la aplicación."
            );
        }

        return $all[$key];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{type: string, label: string, required: bool, rules: array<int, mixed>, slug: string}
     */
    private function normalize(string $key, array $definition): array
    {
        $type = (string) ($definition['type'] ?? 'string');

        if (! array_key_exists($type, self::RULES_BY_TYPE)) {
            throw new InvalidArgumentException(
                "El ajuste [{$key}] declara el tipo [{$type}], que no existe. Los tipos son: "
                .implode(', ', array_keys(self::RULES_BY_TYPE)).'.'
            );
        }

        $required = (bool) ($definition['required'] ?? false);

        /** @var array<int, mixed> $rules */
        $rules = isset($definition['rules'])
            ? array_values((array) $definition['rules'])
            : $this->rulesFor($type, $required);

        return [
            'type' => $type,
            'label' => (string) ($definition['label'] ?? $key),
            'required' => $required,
            'rules' => $rules,
            'slug' => str_replace('.', '_', $key),
        ];
    }

    /**
     * Reglas de un tipo, con el `nullable` cambiado por `required` cuando toca.
     *
     * Un `bool` no lleva ninguno de los dos: `false` es un valor, no una
     * ausencia, y un toggle apagado que fallara la validación por «requerido»
     * sería imposible de guardar.
     *
     * @return array<int, string>
     */
    private function rulesFor(string $type, bool $required): array
    {
        $rules = self::RULES_BY_TYPE[$type];

        if (! $required || $rules[0] !== 'nullable') {
            return $rules;
        }

        $rules[0] = 'required';

        return $rules;
    }
}
