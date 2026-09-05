<x-layouts.app :title="__('Notificaciones')">
    <x-slot:actions>
        <x-kore::button
            :href="route('notifications.preferences')"
            :label="__('Preferencias')"
            icon="settings"
            variant="outline"
        />
    </x-slot:actions>

    <livewire:notifications.table-notifications />
</x-layouts.app>
