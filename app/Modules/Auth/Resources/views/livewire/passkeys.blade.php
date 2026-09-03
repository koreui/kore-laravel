<div>
    {{-- Alta. El formulario NO es de Livewire: la ceremonia WebAuthn la hace el
         navegador y la cierra `POST /user/passkeys` (Fortify). Cuando responde,
         Alpine llama a `$wire.$refresh()` y la lista de abajo se repinta. --}}
    <div class="rounded-2xl border border-kore-border bg-kore-surface p-6"
         x-data="korePasskeys(@js([
             'cancelled' => __('Has cancelado la operación o el dispositivo no la ha confirmado.'),
             'unsupported' => __('Este navegador no admite passkeys.'),
             'exists' => __('Este dispositivo ya tiene una passkey registrada en tu cuenta.'),
             'domain' => __('Las passkeys no se pueden usar en este dominio.'),
             'failed' => __('No hemos podido completar la operación. Inténtalo de nuevo.'),
             'nameRequired' => __('Ponle un nombre a la passkey para reconocerla luego.'),
         ]))">

        <h2 class="text-lg font-semibold tracking-tight">{{ __('Añadir una passkey') }}</h2>
        <p class="mt-1 text-sm text-kore-muted-fg">
            {{ __('Una passkey te deja entrar con la huella, la cara o el PIN del dispositivo, sin escribir la contraseña.') }}
        </p>

        <template x-if="! supported">
            <div class="mt-4">
                <x-kore::alert type="warning" :description="__('Este navegador no admite passkeys.')" />
            </div>
        </template>

        <template x-if="error">
            <div class="mt-4">
                <x-kore::alert type="destructive" live="assertive">
                    <span x-text="error"></span>
                </x-kore::alert>
            </div>
        </template>

        <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
              x-on:submit.prevent="registerPasskey()">
            <div class="flex-1">
                <x-kore::input
                    name="passkey_name"
                    :label="__('Nombre del dispositivo')"
                    :placeholder="__('MacBook del trabajo')"
                    icon="key-round"
                    maxlength="255"
                    x-model="name"
                    x-bind:disabled="busy || ! supported"
                />
            </div>

            <x-kore::button
                type="submit"
                icon="key-round"
                x-bind:disabled="busy || ! supported"
            >
                {{ __('Registrar passkey') }}
            </x-kore::button>
        </form>
    </div>

    {{-- Listado. Los DTOs llegan del componente: nada de Eloquent aquí (R30). --}}
    <div class="mt-6 rounded-2xl border border-kore-border bg-kore-surface">
        <div class="border-b border-kore-border px-6 py-4">
            <h2 class="text-lg font-semibold tracking-tight">{{ __('Tus passkeys') }}</h2>
        </div>

        @if ($this->passkeys === [])
            <div class="px-6 py-10">
                <x-kore::empty-state
                    icon="key-round"
                    :title="__('Todavía no tienes passkeys')"
                    :description="__('Registra una para poder entrar sin contraseña desde este dispositivo.')"
                />
            </div>
        @else
            <ul class="divide-y divide-kore-border">
                @foreach ($this->passkeys as $passkey)
                    <li class="flex flex-wrap items-center justify-between gap-4 px-6 py-4" wire:key="passkey-{{ $passkey->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate font-medium">{{ $passkey->name }}</span>
                                @if ($passkey->authenticator)
                                    <x-kore::badge size="sm" variant="soft" :label="$passkey->authenticator" />
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-kore-muted-fg">
                                {{ __('Registrada el :date', ['date' => $passkey->createdAt]) }}
                                ·
                                @if ($passkey->lastUsedAt)
                                    {{ __('Último uso el :date', ['date' => $passkey->lastUsedAt]) }}
                                @else
                                    {{ __('Sin usar todavía') }}
                                @endif
                            </p>
                        </div>

                        <x-kore::button
                            size="sm"
                            variant="outline"
                            color="destructive"
                            icon="trash-2"
                            wire:click="deletePasskey({{ $passkey->id }})"
                            wire:confirm="{{ __('¿Eliminar la passkey «:name»? El dispositivo dejará de servir para entrar.', ['name' => $passkey->name]) }}"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Eliminar') }}
                        </x-kore::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
