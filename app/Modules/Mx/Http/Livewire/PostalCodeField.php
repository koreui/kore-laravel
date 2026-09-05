<?php

declare(strict_types=1);

namespace App\Modules\Mx\Http\Livewire;

use App\Modules\Mx\Data\PostalCodeData;
use App\Modules\Mx\Support\PostalCodes;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Campo de código postal que se resuelve solo.
 *
 * Es un componente **embebible**: no tiene ruta ni pantalla propia (y por eso no
 * aparece en `tests/e2e/fixtures/access-map.ts`, R52). Se pone dentro del
 * formulario de dirección de quien lo necesite:
 *
 * ```blade
 * <livewire:mx.postal-code-field />
 * ```
 *
 * y el formulario padre escucha el evento con los datos ya resueltos:
 *
 * ```php
 * #[On('mx-postal-code-resolved')]
 * public function fillAddress(array $address): void
 * {
 *     $this->form->municipality = $address['municipality'];
 *     $this->form->state = $address['state_name'];
 * }
 * ```
 *
 * ## Por qué un evento y no `$parent`
 *
 * El componente no sabe cómo se llaman los campos del formulario que lo aloja
 * —ni si hay uno—, así que publica lo que ha averiguado y se desentiende. Un
 * `$parent->...` acoplaría el catálogo de SEPOMEX a la forma del formulario de
 * cada proyecto, que es justo lo que un componente reutilizable no puede hacer.
 *
 * ## Por qué `lookup()` y no `updatedPostalCode()`
 *
 * El hook de Livewire se llamaría `updatedPostalCode()`, y un método público de
 * un componente que empieza por un verbo de escritura es exactamente lo que
 * vigila R23: el check pediría un `authorize()` dentro. Aquí no hay nada que
 * autorizar —el catálogo es público y el componente no escribe—, así que el
 * método se llama por lo que hace y la vista lo dispara con
 * `wire:keyup.debounce`. Un nombre honesto en vez de una excepción.
 */
final class PostalCodeField extends Component
{
    /** El código postal que teclea el usuario. */
    public string $postalCode = '';

    /** La colonia elegida en el desplegable. */
    public ?string $settlement = null;

    public ?string $municipality = null;

    public ?string $stateCode = null;

    public ?string $stateName = null;

    public ?string $city = null;

    /**
     * `true` cuando se han tecleado cinco dígitos y no están en el catálogo.
     *
     * Distinto de «todavía no hay nada»: sin este flag, un código postal
     * inexistente y un campo a medio escribir se pintarían igual.
     */
    public bool $notFound = false;

    /**
     * Opciones del desplegable, con la forma que espera `<x-kore::select>`.
     *
     * Se guarda ya aplanado —y no la lista de DTO— porque es lo que la vista
     * pinta: R30 deja fuera de Blade cualquier cosa que haya que recorrer con
     * lógica.
     *
     * @var list<array{value: string, label: string}>
     */
    public array $settlementOptions = [];

    /**
     * Resuelve el código postal contra el catálogo.
     *
     * La lista de colonias se vacía siempre antes de consultar: si el usuario
     * corrige un dígito, dejar las colonias del código anterior visibles un
     * instante es la forma más fácil de que elija una que no le corresponde.
     */
    public function lookup(PostalCodes $postalCodes): void
    {
        $this->reset(['settlement', 'settlementOptions', 'municipality', 'stateCode', 'stateName', 'city']);
        $this->notFound = false;

        $found = $postalCodes->lookup($this->postalCode);

        if (! $found instanceof PostalCodeData) {
            // Sólo se avisa cuando el usuario ya escribió los cinco dígitos:
            // mientras teclea, no encontrar nada es lo normal.
            $this->notFound = mb_strlen(trim($this->postalCode)) === 5;

            return;
        }

        $this->municipality = $found->municipality;
        $this->stateCode = $found->stateCode;
        $this->stateName = $found->stateName;
        $this->city = $found->city;
        $this->settlementOptions = array_values(array_map(
            static fn (array $item): array => [
                'value' => $item['name'],
                'label' => $item['type'] !== '' ? $item['name'].' ('.$item['type'].')' : $item['name'],
            ],
            $found->settlements,
        ));

        // Una sola colonia no es una elección: se deja puesta.
        if (count($this->settlementOptions) === 1) {
            $this->settlement = $this->settlementOptions[0]['value'];
        }

        $this->dispatch('mx-postal-code-resolved', address: [
            'postal_code' => $found->postalCode,
            'municipality' => $found->municipality,
            'city' => $found->city,
            'state_code' => $found->stateCode,
            'state_name' => $found->stateName,
            'settlements' => array_column($found->settlements, 'name'),
            'settlement' => $this->settlement,
        ]);
    }

    /**
     * Avisa de la colonia elegida.
     *
     * Va aparte del evento de resolución porque ocurre después y sólo a veces:
     * el padre que sólo quiera municipio y estado escucha el primero y ignora
     * éste.
     */
    public function selectSettlement(): void
    {
        $this->dispatch('mx-settlement-selected', settlement: $this->settlement);
    }

    public function render(): View
    {
        return view('mx::livewire.postal-code-field');
    }
}
