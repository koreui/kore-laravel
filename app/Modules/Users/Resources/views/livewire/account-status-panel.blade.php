{{-- El enum resuelve etiqueta y color: la vista no decide nada sobre el
     estado, sólo lo pinta (R30). --}}
<x-kore::card :title="__('Estado de la cuenta')"
              :subtitle="__('Quien no está activo no puede usar la aplicación.')">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <x-kore::badge :color="$this->status->color()" :label="$this->status->label()" size="lg" />

        @if ($this->canChange)
            <div class="flex flex-wrap gap-2">
                @if (! $this->status->canOperate())
                    <x-kore::button wire:click="activate" icon="circle-check"
                        wire:loading.attr="disabled" wire:target="activate"
                        :label="__('Activar')" />
                @else
                    <x-kore::button wire:click="suspend" icon="ban" color="destructive" variant="outline"
                        wire:loading.attr="disabled" wire:target="suspend"
                        :label="__('Suspender')" />
                @endif
            </div>
        @else
            <p class="text-sm text-kore-muted-fg">{{ __('No puedes cambiar el estado de tu propia cuenta.') }}</p>
        @endif
    </div>
</x-kore::card>
