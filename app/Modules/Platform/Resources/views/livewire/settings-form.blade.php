{{--
    Los campos no están escritos aquí: se recorren desde
    `config('kore-settings.editable')` a través de `$this->fields`, que ya llega
    como array (R30). Un derivado que añada un ajuste suyo no toca esta vista.
--}}
<form wire:submit="save">
    <x-kore::card :title="__('Ajustes de la instalación')">
        <div class="space-y-6">

            @foreach ($this->fields as $field)
                @php
                    $model = 'form.values.'.$field['slug'];
                    $id = 'setting-'.$field['slug'];
                @endphp

                <div class="flex items-end gap-3">
                    <div class="flex-1">
                        @switch($field['type'])
                            @case('text')
                                <x-kore::textarea
                                    :id="$id"
                                    :label="$field['label']"
                                    :required="$field['required']"
                                    wire:model="{{ $model }}" />
                                @break

                            @case('bool')
                                <x-kore::toggle
                                    :id="$id"
                                    :label="$field['label']"
                                    wire:model="{{ $model }}" />
                                @break

                            @case('int')
                                <x-kore::input
                                    :id="$id"
                                    :label="$field['label']"
                                    type="number"
                                    :required="$field['required']"
                                    wire:model="{{ $model }}" />
                                @break

                            @case('email')
                                <x-kore::input
                                    :id="$id"
                                    :label="$field['label']"
                                    type="email"
                                    :required="$field['required']"
                                    wire:model="{{ $model }}" />
                                @break

                            @default
                                <x-kore::input
                                    :id="$id"
                                    :label="$field['label']"
                                    :required="$field['required']"
                                    wire:model="{{ $model }}" />
                        @endswitch
                    </div>

                    {{-- Devuelve ESTE ajuste a su valor por defecto borrando su
                         fila. No es «vaciar el campo»: sin fila, la clave vuelve
                         a valer lo que dice config/kore-settings.php. --}}
                    <x-kore::button
                        variant="ghost"
                        color="secondary"
                        size="sm"
                        icon="rotate-ccw"
                        wire:click="restore('{{ $field['slug'] }}')"
                        wire:loading.attr="disabled"
                        :aria-label="__('Restablecer').': '.$field['label']">
                        {{ __('Restablecer') }}
                    </x-kore::button>
                </div>
            @endforeach

        </div>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-kore::button type="submit" wire:loading.attr="disabled">
                    {{ __('Guardar') }}
                </x-kore::button>
            </div>
        </x-slot:footer>
    </x-kore::card>
</form>
