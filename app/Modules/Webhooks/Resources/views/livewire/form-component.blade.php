<form wire:submit="save">
    <x-kore::card :title="$this->title">
        <div class="space-y-6">

            <x-kore::input
                id="form-name"
                :label="__('Nombre')"
                :hint="__('Cómo se llama esta integración en la lista. No viaja en el webhook.')"
                wire:model="form.name"
                name="form.name" />

            <x-kore::input
                id="form-url"
                :label="__('URL de destino')"
                type="url"
                :hint="__('Dónde se hace el POST. Tiene que ser https salvo en local: la firma protege la integridad, no la confidencialidad.')"
                wire:model="form.url"
                name="form.url" />

            <div class="space-y-2">
                <span class="text-sm font-medium">{{ __('Eventos') }}</span>
                <p class="text-sm text-kore-muted-fg">
                    {{ __('Qué se le manda a este endpoint. El catálogo vive en config/kore-webhooks.php.') }}
                </p>

                {{-- El `id` explícito por opción no es cosmética: koreUi deduce el
                     suyo del `name`, y como las cinco casillas comparten
                     `form.events` acabarían con el MISMO id — todas las etiquetas
                     apuntando a la primera, y `getByLabel()` sin poder distinguir
                     ninguna. El error se pinta una sola vez debajo
                     (`:show-error="false"`) por la misma razón. --}}
                <div class="space-y-2 rounded-md border border-kore-border p-4">
                    @foreach ($this->eventOptions as $option)
                        <x-kore::checkbox
                            :id="'form-events-'.$loop->index"
                            :label="$option['label']"
                            :value="$option['value']"
                            :show-error="false"
                            wire:model="form.events"
                            name="form.events" />
                    @endforeach
                </div>

                @error('form.events')
                    <p class="text-sm text-kore-destructive">{{ $message }}</p>
                @enderror
            </div>

            <x-kore::toggle
                :label="__('Activo')"
                :description="__('Un endpoint apagado no acumula cola: al volver a encenderlo no recibirá lo que se perdió.')"
                wire:model="form.active"
                name="form.active" />

            @unless ($this->model)
                <x-kore::alert
                    type="info"
                    :title="__('El secreto se genera solo')"
                    :description="__('Al guardar verás la clave con la que se firman las entregas. Se muestra una sola vez.')" />
            @endunless
        </div>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-kore::button :href="route('webhooks.index')" variant="ghost">
                    {{ __('Cancelar') }}
                </x-kore::button>
                {{-- KORE-E2E-007: hasta que Livewire hidrata, `wire:submit` no está
                     enganchado y un clic enviaría el <form> nativo como GET. Alpine
                     arranca con Livewire, así que el botón nace deshabilitado y se
                     habilita cuando ya hay quien escuche el submit. --}}
                <x-kore::button type="submit" icon="check"
                    x-data="{ hidratado: false }" x-init="hidratado = true"
                    x-bind:disabled="!hidratado"
                    wire:loading.attr="disabled" wire:target="save">
                    {{ __('Guardar') }}
                </x-kore::button>
            </div>
        </x-slot:footer>
    </x-kore::card>
</form>
