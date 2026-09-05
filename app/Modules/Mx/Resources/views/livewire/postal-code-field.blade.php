{{--
    Campo de código postal del módulo Mx.

    Se embebe con <livewire:mx.postal-code-field /> dentro de un formulario de
    dirección; ver el docblock de App\Modules\Mx\Http\Livewire\PostalCodeField.

    `wire:keyup.debounce` en vez de `wire:model.live`: el hook que Livewire
    llamaría entonces se llama updatedPostalCode(), y R23 pide autorización
    dentro de todo método público que empiece por un verbo de escritura. Aquí no
    hay nada que autorizar, así que el método se llama lookup() y la vista lo
    dispara a mano.
--}}
<div class="grid gap-4 sm:grid-cols-2">
    <x-kore::input
        wire:model="postalCode"
        wire:keyup.debounce.400ms="lookup"
        :label="__('Código postal')"
        {{-- Un ejemplo de código postal no se traduce: es un número. --}}
        placeholder="01000"
        inputmode="numeric"
        maxlength="5"
        autocomplete="postal-code"
        :error="$notFound ? __('Ese código postal no está en el catálogo.') : null"
    />

    <x-kore::select
        wire:model="settlement"
        wire:change="selectSettlement"
        native
        :label="__('Colonia')"
        :options="$settlementOptions"
        :placeholder="__('Elige una colonia')"
        :disabled="$settlementOptions === []"
    />

    @if ($municipality !== null)
        <div class="text-sm text-kore-muted-fg sm:col-span-2">
            <p>
                <span class="font-medium text-kore-fg">{{ __('Municipio') }}:</span>
                {{ $municipality }}
            </p>
            <p>
                <span class="font-medium text-kore-fg">{{ __('Entidad federativa') }}:</span>
                {{ $stateName }}
            </p>
            @if ($city !== null)
                <p>
                    <span class="font-medium text-kore-fg">{{ __('Ciudad') }}:</span>
                    {{ $city }}
                </p>
            @endif
        </div>
    @endif
</div>
