<x-layouts.app :title="__('Preferencias de notificación')">
    <x-slot:actions>
        <x-kore::button
            :href="route('notifications.index')"
            :label="__('Volver a la bandeja')"
            icon="arrow-left"
            variant="ghost"
        />
    </x-slot:actions>

    <livewire:notifications.settings />
</x-layouts.app>
