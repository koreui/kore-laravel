<x-layouts.app :title="__('Dashboard')">

    {{-- Saludo --}}
    <div class="relative overflow-hidden rounded-2xl border border-kore-border bg-kore-surface p-8">
        <div class="absolute inset-0 -z-10">
            <div class="absolute inset-0 bg-gradient-to-br from-kore-primary/5 via-transparent to-kore-info/5"></div>
            <div class="absolute right-0 top-0 h-64 w-64 rounded-full bg-kore-primary/10 opacity-30 blur-3xl"></div>
        </div>

        <h2 class="text-2xl font-bold tracking-tight">
            {{ __('Hola, :name', ['name' => auth()->user()->name]) }} 👋
        </h2>
        <p class="mt-1.5 text-kore-muted-fg">
            {{ __('Bienvenido a tu boilerplate kore-laravel. Empieza por gestionar usuarios o consulta la documentación.') }}
        </p>
    </div>

    {{-- Stats grid --}}
    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        @php
            $stats = [
                ['label' => __('Usuarios totales'), 'value' => \App\Models\User::count(), 'icon' => 'users'],
                ['label' => __('Permisos del sistema'), 'value' => \Spatie\Permission\Models\Permission::count(), 'icon' => 'shield-check'],
                ['label' => __('Módulos activos'), 'value' => \App\Modules\Auth\Models\Module::where('active', true)->count(), 'icon' => 'layers'],
            ];
        @endphp

        @foreach ($stats as $s)
            <div class="rounded-xl border border-kore-border bg-kore-surface p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-kore-muted-fg">{{ $s['label'] }}</span>
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-kore-primary/10">
                        <x-kore::icon :name="$s['icon']" class="h-4 w-4 text-kore-primary" />
                    </div>
                </div>
                <div class="mt-3 font-mono text-3xl font-bold tracking-tight">{{ $s['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Acciones rápidas --}}
    <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        @can('users.view')
            <a href="{{ route('users.index') }}"
               class="group flex items-start justify-between gap-4 rounded-xl border border-kore-border bg-kore-surface p-5 transition-colors hover:border-kore-primary/50">
                <div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-kore-primary/10">
                        <x-kore::icon name="users" class="h-5 w-5 text-kore-primary" />
                    </div>
                    <h3 class="mt-3 font-semibold">{{ __('Gestionar usuarios') }}</h3>
                    <p class="mt-1 text-sm text-kore-muted-fg">{{ __('Crear, editar y asignar roles a los usuarios del sistema.') }}</p>
                </div>
                <x-kore::icon name="arrow-up-right" class="h-4 w-4 text-kore-muted-fg transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
            </a>
        @endcan

        <a href="/docs"
           class="group flex items-start justify-between gap-4 rounded-xl border border-kore-border bg-kore-surface p-5 transition-colors hover:border-kore-primary/50">
            <div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-kore-primary/10">
                    <x-kore::icon name="book-open" class="h-5 w-5 text-kore-primary" />
                </div>
                <h3 class="mt-3 font-semibold">{{ __('Documentación') }}</h3>
                <p class="mt-1 text-sm text-kore-muted-fg">{{ __('Arquitectura, patrón CRUD, autorización y deployment.') }}</p>
            </div>
            <x-kore::icon name="arrow-up-right" class="h-4 w-4 text-kore-muted-fg transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
        </a>
    </div>

</x-layouts.app>
