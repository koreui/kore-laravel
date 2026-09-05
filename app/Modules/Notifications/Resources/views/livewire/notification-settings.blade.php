{{--
    Preferencias: una fila por categoría del catálogo, tres interruptores.

    El de push sólo aparece con el módulo Devices encendido: sin inventario de
    dispositivos no hay a dónde mandar un push, y ofrecer un interruptor que no
    hace nada es prometer algo que no ocurre.
--}}
<x-kore::card :title="__('Preferencias de notificación')" :subtitle="__('Elige de qué te avisamos y por dónde.')">
    <div class="space-y-4">
        @foreach ($this->categories as $categoria)
            <fieldset class="rounded-kore-lg border border-kore-border p-4">
                <legend class="px-1 font-medium text-kore-fg">{{ $categoria['label'] }}</legend>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-kore::toggle
                        :label="__('En la bandeja')"
                        wire:model="preferences.{{ $categoria['value'] }}.in_app"
                        :name="'preferences.'.$categoria['value'].'.in_app'"
                    />

                    <x-kore::toggle
                        :label="__('Correo')"
                        wire:model="preferences.{{ $categoria['value'] }}.mail"
                        :name="'preferences.'.$categoria['value'].'.mail'"
                    />

                    @if ($this->pushAvailable)
                        <x-kore::toggle
                            :label="__('Push')"
                            wire:model="preferences.{{ $categoria['value'] }}.push"
                            :name="'preferences.'.$categoria['value'].'.push'"
                        />
                    @endif
                </div>
            </fieldset>
        @endforeach

        @unless ($this->pushAvailable)
            {{-- Honestidad con quien lee: no se ofrece un canal que esta
                 instalación no puede usar. --}}
            <x-kore::alert type="info" :title="__('Canales disponibles')">
                {{ __('Los avisos llegan a tu bandeja y, si lo pides, por correo. Las notificaciones push necesitan el módulo de dispositivos.') }}
            </x-kore::alert>
        @endunless
    </div>

    <x-slot:footer>
        <div class="flex justify-end">
            <x-kore::button icon="check" :label="__('Guardar preferencias')" wire:click="save" wire:loading.attr="disabled" />
        </div>
    </x-slot:footer>
</x-kore::card>
