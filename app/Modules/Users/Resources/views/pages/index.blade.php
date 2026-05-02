<x-layouts.app :title="__('Usuarios')">
    @can('users.create')
        <x-slot:actions>
            <x-kore::button :href="route('users.create')" icon="plus">
                {{ __('Nuevo usuario') }}
            </x-kore::button>
        </x-slot:actions>
    @endcan

    <div class="rounded-xl border border-[--kore-border] bg-[--kore-bg-elevated] p-1">
        <livewire:users.table-users />
    </div>
</x-layouts.app>
