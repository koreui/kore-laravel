{{--
    Zona de subida de un slot, con el archivo vigente delante.

        <x-files::slot-upload
            :current="$this->avatar"
            :label="__('Foto de perfil')"
            accept="image/*"
            :max-size="2"
        />

    `current` es un ARRAY (o null), nunca un modelo: lo prepara el componente
    Livewire a partir de `StoredFileData` (R30). Cuatro claves, que son las que
    `<x-kore::upload static>` entiende — `name`, `size`, `type`, `url`.

    ## Por qué son DOS `<x-kore::upload>` y no uno

    En koreUi `static` y la zona de subida se excluyen: con `static` el
    componente pinta la lista de ficheros existentes y **no** dibuja el
    dropzone. Así que el archivo vigente va en uno (estático, con papelera) y la
    zona para subir el siguiente va en otro (con `wire:model`). Es también lo
    que mejor describe lo que pasa: arriba lo que hay, abajo lo que sustituye a
    lo que hay.

    La papelera del primero llama a `deleteUpload`, que
    `App\Core\Concerns\HandlesSlotUploads` implementa como **archivar**: el
    fichero no se destruye, deja de ser el vigente.

    ## Por qué hay un botón y no se guarda solo

    Elegir el fichero lo sube a la carpeta temporal de Livewire; guardarlo en el
    slot es otra cosa, y lleva su propia confirmación. Hacerlo en el hook
    `updated` ahorraría un clic y traería dos problemas: la versión nueva
    quedaría creada aunque quien la eligió se arrepienta y cierre la pantalla, y
    el componente pasaría a versionar en cada `set()` —también los de los
    tests—. El botón es `type="button"` a propósito: este componente vive dentro
    del `<form wire:submit>` de la pantalla y un submit se llevaría por delante
    el formulario entero.
--}}

@props([
    'current' => null,
    'label' => null,
    'hint' => null,
    'accept' => null,
    'maxSize' => null,
    'deletable' => true,
    'disabled' => false,
    'action' => null,
    // Raíz de los `id` de los dos controles. Se hace explícito porque koreUi
    // los deriva del `name`, y el bloque estático no tiene ninguno: sin esto
    // los dos `<label for>` apuntarían al mismo sitio. Cámbialo si una pantalla
    // llega a tener dos slots a la vez.
    'id' => 'slot-upload',
])

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @if ($current)
        <x-kore::upload
            :id="$id.'-current'"
            :label="$label"
            static
            :static-files="[$current]"
            :deletable="$deletable && ! $disabled"
        />
    @endif

    <x-kore::upload
        :id="$id.'-input'"
        :label="$current ? __('Sustituir por otro archivo') : $label"
        :hint="$hint"
        wire:model="slotUpload"
        name="slotUpload"
        :accept="$accept"
        :max-size="$maxSize"
        :disabled="$disabled"
    />

    @unless ($disabled)
        <div class="flex justify-end">
            <x-kore::button
                type="button"
                variant="outline"
                icon="upload"
                wire:click="uploadSlot"
                wire:loading.attr="disabled"
                wire:target="uploadSlot,slotUpload"
            >
                {{ $action ?? __('Guardar archivo') }}
            </x-kore::button>
        </div>
    @endunless
</div>
