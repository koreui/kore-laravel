@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" x-data>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|jetbrains-mono:400,500" rel="stylesheet" />

    @koreThemeScript
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full antialiased bg-kore-bg text-kore-fg">

    @php
        $user = auth()->user();
    @endphp

    {{-- App shell de koreUi: sidebar + navbar + contenido. El estado colapsado/expandido
         se resuelve en el servidor (cookie kore_sidebar), así que no hay salto en el
         primer paint.

         Desde koreUi 2.0 el shell pinta por su cuenta un enlace «Saltar al contenido»
         como primer elemento del documento y le da al <main> `id="kore-contenido"` y
         `tabindex="-1"`. Se quita con :skip-link="false".

         Ver docs/shell en koreui.ovilla.dev. --}}
    <x-kore::shell>

        {{-- ── SIDEBAR ───────────────────────────────────────────────── --}}
        <x-slot:sidebar>
            {{-- navigate: wire:navigate en todos los enlaces del menú. Lo heredan
                 los sidebar.item vía @aware. --}}
            <x-kore::sidebar :navigate="true">
                <x-slot:header>
                    <a href="{{ route('dashboard') }}"
                       class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-kore-primary text-sm font-bold text-white"
                       aria-label="{{ config('app.name') }}">K</a>
                    <span class="kore-sidebar-label truncate text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
                </x-slot:header>

                <x-kore::sidebar.group label="{{ __('Workspace') }}" separator="none">
                    <x-kore::sidebar.item
                        label="{{ __('Dashboard') }}"
                        icon="layout-dashboard"
                        route="dashboard"
                        match="dashboard" />
                </x-kore::sidebar.group>

                @can('users.view')
                    <x-kore::sidebar.group label="{{ __('Gestión') }}">
                        <x-kore::sidebar.item
                            label="{{ __('Usuarios') }}"
                            icon="users"
                            route="users.index"
                            match="users.*" />
                    </x-kore::sidebar.group>
                @endcan

                {{-- Dos condiciones, y las dos hacen falta: el toggle decide si el
                     módulo existe (sin él no hay ruta `webhooks.index` que pintar)
                     y el permiso, si esta persona entra. Un enlace a una pantalla
                     que devuelve 403 es peor que no tener el enlace. --}}
                @if ((bool) config('kore-app.webhooks.enabled'))
                    @can('webhooks.manage')
                        <x-kore::sidebar.group label="{{ __('Integraciones') }}">
                            <x-kore::sidebar.item
                                label="{{ __('Webhooks') }}"
                                icon="webhook"
                                route="webhooks.index"
                                match="webhooks.*" />
                        </x-kore::sidebar.group>
                    @endcan
                @endif

                {{-- La ruta sólo existe con AUTH_PASSKEYS=true (R10), así que el
                     enlace se pregunta por ella y no por el toggle. --}}
                @if (Route::has('passkeys.index'))
                    <x-kore::sidebar.group label="{{ __('Cuenta') }}">
                        <x-kore::sidebar.item
                            label="{{ __('Passkeys') }}"
                            icon="key-round"
                            route="passkeys.index"
                            match="passkeys.*" />
                    </x-kore::sidebar.group>
                @endif

                {{-- Card de usuario: avatar + nombre/email, tema y logout. Al colapsar el
                     sidebar, `kore-sidebar-link` centra el avatar/icono y `kore-sidebar-label`
                     oculta el texto y el theme-switch (modo iconos). --}}
                <x-slot:footer>
                    <li class="kore-sidebar-link rounded-kore-md py-1.5">
                        <x-kore::avatar :name="$user->name" size="sm" class="shrink-0" />
                        <div class="kore-sidebar-label min-w-0 flex-1 leading-tight">
                            <div class="truncate text-sm font-medium text-kore-fg">{{ $user->name }}</div>
                            <div class="truncate text-xs text-kore-muted-fg">{{ $user->email }}</div>
                        </div>
                    </li>

                    <li class="kore-sidebar-link py-0.5">
                        <div class="kore-sidebar-label flex-1">
                            <x-kore::theme-switch size="sm" />
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                            @csrf
                            <button type="submit"
                                    class="inline-flex size-9 items-center justify-center rounded-kore-md text-kore-muted-fg transition-colors hover:bg-kore-muted hover:text-kore-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kore-ring/50"
                                    aria-label="{{ __('Cerrar sesión') }}"
                                    title="{{ __('Cerrar sesión') }}">
                                <x-kore::icon name="log-out" class="size-5" />
                            </button>
                        </form>
                    </li>
                </x-slot:footer>
            </x-kore::sidebar>
        </x-slot:sidebar>

        {{-- ── NAVBAR ────────────────────────────────────────────────── --}}
        <x-slot:navbar>
            <x-kore::navbar>
                {{-- Buscador global: dispara el spotlight (⌘K) de koreUi. --}}
                <x-slot:start>
                    <button type="button"
                            x-on:click="window.dispatchEvent(new CustomEvent('kore:spotlight-open'))"
                            class="flex w-48 items-center gap-2 rounded-lg border border-kore-border bg-kore-surface px-3 py-1.5 text-sm text-kore-muted-fg transition-colors hover:border-kore-primary/40 sm:w-64 lg:w-72">
                        <x-kore::icon name="search" class="size-4 shrink-0" />
                        <span class="flex-1 text-left">{{ __('Buscar...') }}</span>
                        <x-kore::kbd size="sm" class="hidden sm:inline-flex">⌘K</x-kore::kbd>
                    </button>
                </x-slot:start>

            </x-kore::navbar>
        </x-slot:navbar>

        {{-- ── CONTENIDO ─────────────────────────────────────────────── --}}
        <div class="p-6 md:p-8">
            @if ($title)
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
                    @isset($actions)
                        <div class="flex items-center gap-2">{{ $actions }}</div>
                    @endisset
                </div>
            @endif

            {{ $slot }}
        </div>
    </x-kore::shell>

    <livewire:kore-feedback-manager />
    <livewire:kore-overlay-manager />
    <x-kore::spotlight />

    @koreScripts
    @livewireScripts
</body>
</html>
