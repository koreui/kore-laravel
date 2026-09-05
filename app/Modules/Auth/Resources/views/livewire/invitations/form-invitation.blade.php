<div class="space-y-6">

    {{-- El código sólo se ve aquí: no hay pantalla de detalle y la tabla lo
         muestra pequeño. Por eso la pantalla se queda tras guardar en vez de
         redirigir al listado. --}}
    @if ($createdCode)
        <x-kore::card :title="__('Código listo para repartir')"
                      :subtitle="__('Cópialo ahora: quien lo reciba lo escribirá al crear su cuenta.')">
            <div class="space-y-4">
                <x-kore::clipboard :text="$createdCode" :label="__('Código')" class="w-full" />

                <div class="flex flex-wrap gap-2">
                    <x-kore::button :href="route('invitations.index')" variant="ghost"
                        :label="__('Ver todas las invitaciones')" icon="list" />
                </div>
            </div>
        </x-kore::card>
    @endif

    <form wire:submit="save">
        <x-kore::card :title="__('Nueva invitación')"
                      :subtitle="__('Quien use este código creará su cuenta con el rol que elijas aquí.')">
            <div class="space-y-6">

                <x-kore::select
                    id="form-role"
                    :label="__('Rol de quien se registre')"
                    :options="$this->roles"
                    wire:model="form.role"
                    name="form.role"
                    native
                    required
                />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-kore::input
                        id="form-max-uses"
                        type="number"
                        min="1"
                        :label="__('Límite de registros')"
                        :hint="__('En blanco, sin límite.')"
                        wire:model="form.max_uses"
                        name="form.max_uses"
                    />

                    <x-kore::input
                        id="form-expires-at"
                        type="datetime-local"
                        :label="__('Caduca el')"
                        :hint="__('En blanco, no caduca.')"
                        wire:model="form.expires_at"
                        name="form.expires_at"
                    />
                </div>

                <x-kore::input
                    id="form-note"
                    :label="__('Nota')"
                    :hint="__('Para qué es este código. Sólo lo ves tú.')"
                    wire:model="form.note"
                    name="form.note"
                />
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-kore::button :href="route('invitations.index')" variant="ghost">
                        {{ __('Cancelar') }}
                    </x-kore::button>
                    {{-- KORE-E2E-007: hasta que Livewire hidrata, `wire:submit` no está
                         enganchado y un clic enviaría el <form> nativo como GET. El botón
                         nace deshabilitado y Alpine —que arranca con Livewire— lo habilita. --}}
                    <x-kore::button type="submit" icon="check"
                        x-data="{ hidratado: false }" x-init="hidratado = true"
                        x-bind:disabled="!hidratado"
                        wire:loading.attr="disabled" wire:target="save">
                        {{ __('Crear invitación') }}
                    </x-kore::button>
                </div>
            </x-slot:footer>
        </x-kore::card>
    </form>
</div>
