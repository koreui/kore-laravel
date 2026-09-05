<x-layouts.app :title="__('Webhooks')">
    <x-slot:actions>
        <x-kore::button :href="route('webhooks.create')" :label="__('Nuevo endpoint')" icon="plus" />
    </x-slot:actions>

    <div class="rounded-xl border border-kore-border bg-kore-surface p-1">
        <livewire:webhooks.table-endpoints />
    </div>
</x-layouts.app>
