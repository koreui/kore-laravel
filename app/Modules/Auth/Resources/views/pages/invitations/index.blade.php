<x-layouts.app :title="__('Invitaciones')">
    @can('invitations.manage')
        <x-slot:actions>
            <x-kore::button :href="route('invitations.create')" :label="__('Nueva invitación')" icon="plus" />
        </x-slot:actions>
    @endcan

    <div class="rounded-xl border border-kore-border bg-kore-surface p-1">
        <livewire:auth.invitations.table-invitations />
    </div>
</x-layouts.app>
