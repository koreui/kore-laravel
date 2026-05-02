<x-layouts.app :title="__('Dashboard')">

    {{-- Saludo --}}
    <div class="rounded-2xl border border-[--kore-border] bg-mesh p-8">
        <div class="relative">
            <h2 class="text-2xl font-semibold tracking-tight">
                {{ __('Hola, :name', ['name' => auth()->user()->name]) }} 👋
            </h2>
            <p class="mt-1 text-sm text-[--kore-text-muted]">
                {{ __('Bienvenido a tu boilerplate kore-laravel. Empieza por gestionar usuarios o consulta la documentación.') }}
            </p>
        </div>
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
            <div class="rounded-xl border border-[--kore-border] bg-[--kore-bg-elevated] p-5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wider text-[--kore-text-muted]">{{ $s['label'] }}</span>
                    <x-kore::icon :name="$s['icon']" class="size-4 text-[--kore-text-muted]" />
                </div>
                <div class="mt-3 font-mono text-3xl font-semibold tracking-tight">{{ $s['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Acciones rápidas --}}
    <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        @can('users.view')
            <a href="{{ route('users.index') }}"
               class="group flex items-start justify-between gap-4 rounded-xl border border-[--kore-border] bg-[--kore-bg-elevated] p-5 transition-all hover:-translate-y-0.5 hover:border-[--kore-primary]/40">
                <div>
                    <div class="flex size-9 items-center justify-center rounded-lg border border-[--kore-border] bg-[--kore-bg] text-[--kore-primary]">
                        <x-kore::icon name="users" class="size-4" />
                    </div>
                    <h3 class="mt-3 font-semibold">{{ __('Gestionar usuarios') }}</h3>
                    <p class="mt-1 text-sm text-[--kore-text-muted]">{{ __('Crear, editar y asignar roles a los usuarios del sistema.') }}</p>
                </div>
                <x-kore::icon name="arrow-up-right" class="size-4 text-[--kore-text-muted] transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
            </a>
        @endcan

        <a href="/docs"
           class="group flex items-start justify-between gap-4 rounded-xl border border-[--kore-border] bg-[--kore-bg-elevated] p-5 transition-all hover:-translate-y-0.5 hover:border-[--kore-primary]/40">
            <div>
                <div class="flex size-9 items-center justify-center rounded-lg border border-[--kore-border] bg-[--kore-bg] text-[--kore-primary]">
                    <x-kore::icon name="book-open" class="size-4" />
                </div>
                <h3 class="mt-3 font-semibold">{{ __('Documentación') }}</h3>
                <p class="mt-1 text-sm text-[--kore-text-muted]">{{ __('Arquitectura, patrón CRUD, autorización y deployment.') }}</p>
            </div>
            <x-kore::icon name="arrow-up-right" class="size-4 text-[--kore-text-muted] transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
        </a>
    </div>

</x-layouts.app>
