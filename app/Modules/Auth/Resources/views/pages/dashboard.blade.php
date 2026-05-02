<x-layouts.app :title="__('Dashboard')">
    <x-kore::card>
        <p class="text-sm text-[--kore-text-muted]">
            {{ __('Hola, :name. Estás logueado en kore-laravel.', ['name' => auth()->user()->name]) }}
        </p>

        <div class="mt-4 flex flex-wrap gap-3">
            @can('users.view')
                <x-kore::button :href="route('users.index')" icon="users" variant="outline">
                    {{ __('Gestionar usuarios') }}
                </x-kore::button>
            @endcan
        </div>
    </x-kore::card>
</x-layouts.app>
