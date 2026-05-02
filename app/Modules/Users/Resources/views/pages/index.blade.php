<x-layouts.app :title="__('Usuarios')">
    @can('users.create')
        <x-slot:actions>
            <x-kore::button :href="route('users.create')" :label="__('Nuevo usuario')" icon="plus" />
        </x-slot:actions>
    @endcan

    <div class="rounded-xl border border-kore-border bg-kore-surface p-1">
        <livewire:users.table-users />
    </div>
</x-layouts.app>
