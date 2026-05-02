<x-layouts.app :title="__('Usuarios')">
    <x-slot:title>{{ __('Usuarios') }}</x-slot:title>

    <div class="flex justify-end">
        @can('users.create')
            <x-kore::button :href="route('users.create')" icon="plus">
                {{ __('Nuevo usuario') }}
            </x-kore::button>
        @endcan
    </div>

    <livewire:users.table-users />
</x-layouts.app>
