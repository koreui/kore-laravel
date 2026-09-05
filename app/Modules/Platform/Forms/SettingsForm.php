<?php

declare(strict_types=1);

namespace App\Modules\Platform\Forms;

use App\Modules\Platform\Data\SettingsFormData;
use App\Modules\Platform\Support\EditableSettings;
use Livewire\Form;

/**
 * Livewire Form Object de la pantalla de ajustes.
 *
 * Como el `UserForm`: valida y traduce, no persiste (R4). Escribir es trabajo
 * de `SettingUpdateAction`, que así sirve igual desde un comando artisan.
 *
 * La diferencia con `UserForm` es que aquí **los campos no están escritos**: se
 * derivan de `config('kore-settings.editable')`, así que un derivado que añada
 * un ajuste suyo pone tres líneas en el archivo de configuración y no toca este
 * archivo, ni la vista, ni la Action.
 *
 * ## Por qué el estado va por `slug` y no por clave
 *
 * El punto es el separador de niveles en las dos capas que atraviesa el valor:
 * `wire:model="form.values.organization.name"` hace un `data_set` que crea
 * `values['organization']['name']`, y el validador interpreta lo mismo. La clave
 * `organization.name` desaparecería por el camino. Así que el estado se guarda
 * por slug (`organization_name`) y `toData()` deshace la traducción.
 */
final class SettingsForm extends Form
{
    /**
     * Los valores del formulario, indexados por el `slug` de cada ajuste.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * Rellena el formulario con los valores efectivos.
     *
     * @param array<string, mixed> $settings mapa `{clave => valor}` de `Settings::all()`
     */
    public function fillFromSettings(array $settings): void
    {
        $values = [];

        foreach ($this->editable()->bySlug() as $slug => $definition) {
            $value = $settings[$definition['key']] ?? null;

            $values[$slug] = $definition['type'] === 'bool' ? (bool) $value : $value;
        }

        $this->values = $values;
    }

    /**
     * Reglas derivadas del `type` de cada ajuste editable.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ['values' => ['array']];

        foreach ($this->editable()->bySlug() as $slug => $definition) {
            $rules["values.{$slug}"] = $definition['rules'];
        }

        return $rules;
    }

    /**
     * Etiquetas en español para los mensajes de validación, que si no dirían
     * «values.organization name».
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->editable()->bySlug() as $slug => $definition) {
            $attributes["values.{$slug}"] = mb_strtolower($definition['label']);
        }

        return $attributes;
    }

    /**
     * El estado validado, ya con las claves de verdad.
     *
     * Llamar SIEMPRE después de `validate()`: `SettingsFormData` no valida nada.
     *
     * Una cadena vacía se guarda como `null`: en un `<input>` vacío no hay
     * diferencia entre «no lo he puesto» y «lo he puesto en blanco», y guardar
     * `''` dejaría el ajuste tapando su defecto con nada.
     */
    public function toData(): SettingsFormData
    {
        $values = [];

        foreach ($this->editable()->bySlug() as $slug => $definition) {
            if (! array_key_exists($slug, $this->values)) {
                continue;
            }

            $value = $this->values[$slug];

            $values[$definition['key']] = match ($definition['type']) {
                'bool' => (bool) $value,
                'int' => $value === null || $value === '' ? null : (int) $value,
                default => $value === '' ? null : $value,
            };
        }

        return new SettingsFormData($values);
    }

    private function editable(): EditableSettings
    {
        return resolve(EditableSettings::class);
    }
}
