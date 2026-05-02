@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" x-data>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>
    @koreThemeScript
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full antialiased bg-[--kore-bg] text-[--kore-text]"
      x-data="{
          sidebarOpen: localStorage.getItem('kore.sidebar.collapsed') !== 'true',
          mobileOpen: false,
          toggleSidebar() {
              this.sidebarOpen = !this.sidebarOpen;
              localStorage.setItem('kore.sidebar.collapsed', !this.sidebarOpen);
          },
      }">

    @php
        $nav = [
            ['label' => __('Dashboard'), 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'permission' => null],
            ['label' => __('Usuarios'), 'icon' => 'users', 'route' => 'users.index', 'permission' => 'users.view'],
        ];
        $current = request()->route()?->getName();
    @endphp

    <div class="flex min-h-screen">

        {{-- ── SIDEBAR (desktop) ─────────────────────────────────────── --}}
        <aside
            class="hidden border-r border-[--kore-border] bg-[--kore-bg-elevated] transition-all duration-200 ease-[cubic-bezier(.16,1,.3,1)] lg:flex lg:flex-col"
            :class="sidebarOpen ? 'w-64' : 'w-16'">

            {{-- Logo + collapse --}}
            <div class="flex h-14 items-center justify-between border-b border-[--kore-border] px-3">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 overflow-hidden">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[--kore-primary] text-white">
                        <x-kore::icon name="hexagon" class="size-4" />
                    </span>
                    <span class="truncate text-sm font-semibold tracking-tight" x-show="sidebarOpen" x-cloak>
                        {{ config('app.name') }}
                    </span>
                </a>
                <button type="button"
                        @click="toggleSidebar()"
                        class="rounded-md p-1.5 text-[--kore-text-muted] hover:bg-[--kore-bg-muted] hover:text-[--kore-text]"
                        :title="sidebarOpen ? '{{ __('Colapsar') }}' : '{{ __('Expandir') }}'">
                    <x-kore::icon name="chevrons-left" class="size-4 transition-transform"
                                  ::class="!sidebarOpen && 'rotate-180'" />
                </button>
            </div>

            {{-- Nav items --}}
            <nav class="flex-1 space-y-1 overflow-y-auto p-2">
                <div class="px-2 py-2 text-[10px] font-semibold uppercase tracking-wider text-[--kore-text-muted]"
                     x-show="sidebarOpen" x-cloak>
                    {{ __('Workspace') }}
                </div>

                @foreach ($nav as $item)
                    @if ($item['permission'] === null || auth()->user()?->can($item['permission']))
                        @php $isActive = $current && str_starts_with($current, str_replace('.index', '', $item['route'])); @endphp
                        <a href="{{ route($item['route']) }}"
                           class="group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors {{ $isActive ? 'bg-[--kore-primary]/10 text-[--kore-primary] font-medium' : 'text-[--kore-text-muted] hover:bg-[--kore-bg-muted] hover:text-[--kore-text]' }}"
                           :title="!sidebarOpen ? '{{ $item['label'] }}' : ''">
                            <x-kore::icon :name="$item['icon']" class="size-4 shrink-0" />
                            <span x-show="sidebarOpen" x-cloak class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </nav>

            {{-- User block + theme switch --}}
            <div class="border-t border-[--kore-border] p-2">
                <div class="flex items-center gap-2 rounded-lg p-2">
                    <x-kore::avatar :name="auth()->user()->name" size="sm" />
                    <div class="flex-1 overflow-hidden" x-show="sidebarOpen" x-cloak>
                        <div class="truncate text-sm font-medium">{{ auth()->user()->name }}</div>
                        <div class="truncate text-xs text-[--kore-text-muted]">{{ auth()->user()->email }}</div>
                    </div>
                </div>
                <div class="mt-1 flex items-center gap-1" :class="sidebarOpen ? 'justify-between px-2' : 'flex-col'">
                    <x-kore::theme-switch size="sm" />
                    <form method="POST" action="{{ route('logout') }}" class="flex">
                        @csrf
                        <button type="submit"
                                class="rounded-md p-1.5 text-[--kore-text-muted] hover:bg-[--kore-bg-muted] hover:text-[--kore-text]"
                                title="{{ __('Cerrar sesión') }}">
                            <x-kore::icon name="log-out" class="size-4" />
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- ── SIDEBAR mobile (drawer) ──────────────────────────────── --}}
        <div x-show="mobileOpen"
             x-cloak
             @keydown.escape.window="mobileOpen = false"
             class="fixed inset-0 z-50 lg:hidden">
            <div x-show="mobileOpen"
                 x-transition:enter="transition-opacity duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-black/50"
                 @click="mobileOpen = false"></div>

            <aside x-show="mobileOpen"
                   x-transition:enter="transition-transform duration-200 ease-[cubic-bezier(.16,1,.3,1)]"
                   x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition-transform duration-150"
                   x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                   class="absolute left-0 top-0 flex h-full w-64 flex-col border-r border-[--kore-border] bg-[--kore-bg-elevated]">

                <div class="flex h-14 items-center justify-between border-b border-[--kore-border] px-3">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[--kore-primary] text-white">
                            <x-kore::icon name="hexagon" class="size-4" />
                        </span>
                        <span class="text-sm font-semibold tracking-tight">{{ config('app.name') }}</span>
                    </a>
                    <button type="button" @click="mobileOpen = false"
                            class="rounded-md p-1.5 text-[--kore-text-muted] hover:bg-[--kore-bg-muted]">
                        <x-kore::icon name="x" class="size-4" />
                    </button>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto p-2">
                    @foreach ($nav as $item)
                        @if ($item['permission'] === null || auth()->user()?->can($item['permission']))
                            @php $isActive = $current && str_starts_with($current, str_replace('.index', '', $item['route'])); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm {{ $isActive ? 'bg-[--kore-primary]/10 text-[--kore-primary] font-medium' : 'text-[--kore-text-muted] hover:bg-[--kore-bg-muted]' }}">
                                <x-kore::icon :name="$item['icon']" class="size-4" />
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>

                <div class="border-t border-[--kore-border] p-3">
                    <div class="mb-3 flex items-center gap-2">
                        <x-kore::avatar :name="auth()->user()->name" size="sm" />
                        <div class="flex-1 overflow-hidden">
                            <div class="truncate text-sm font-medium">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-[--kore-text-muted]">{{ auth()->user()->email }}</div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <x-kore::theme-switch size="sm" />
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-[--kore-text-muted] hover:text-[--kore-text]">
                                {{ __('Cerrar sesión') }}
                            </button>
                        </form>
                    </div>
                </div>
            </aside>
        </div>

        {{-- ── MAIN ──────────────────────────────────────────────────── --}}
        <div class="flex flex-1 flex-col">

            {{-- Header --}}
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-[--kore-border] bg-[--kore-bg]/80 px-4 backdrop-blur md:px-6">
                <button type="button"
                        @click="mobileOpen = true"
                        class="rounded-md p-1.5 text-[--kore-text-muted] hover:bg-[--kore-bg-muted] hover:text-[--kore-text] lg:hidden">
                    <x-kore::icon name="menu" class="size-5" />
                </button>

                {{-- Search trigger (Cmd+K) --}}
                <button type="button"
                        @click="$dispatch('kore-spotlight:open')"
                        class="flex w-full max-w-xs items-center gap-2 rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated] px-3 py-1.5 text-sm text-[--kore-text-muted] transition-colors hover:border-[--kore-primary]/40 md:max-w-md">
                    <x-kore::icon name="search" class="size-4" />
                    <span class="flex-1 text-left">{{ __('Buscar...') }}</span>
                    <x-kore::kbd>⌘K</x-kore::kbd>
                </button>

                <div class="ml-auto flex items-center gap-2">
                    @if ($title)
                        <span class="hidden text-sm text-[--kore-text-muted] md:inline">{{ $title }}</span>
                    @endif
                </div>
            </header>

            {{-- Content --}}
            <main class="flex-1 overflow-x-hidden p-6 md:p-8">
                @if ($title)
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                        @isset($actions)
                            <div class="flex items-center gap-2">{{ $actions }}</div>
                        @endisset
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

    <livewire:kore-feedback-manager />
    <livewire:kore-overlay-manager />
    <x-kore::spotlight />

    @koreScripts
    @livewireScripts
</body>
</html>
