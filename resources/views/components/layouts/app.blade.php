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
<body class="min-h-full antialiased bg-[--kore-bg] text-[--kore-text]">
    <header class="border-b border-[--kore-border]">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="text-lg font-semibold tracking-tight">
                    {{ config('app.name') }}
                </a>
                <nav class="flex items-center gap-4 text-sm text-[--kore-text-muted]">
                    <a href="{{ route('dashboard') }}" class="hover:text-[--kore-text]">{{ __('Dashboard') }}</a>
                    @can('users.view')
                        <a href="{{ route('users.index') }}" class="hover:text-[--kore-text]">{{ __('Usuarios') }}</a>
                    @endcan
                </nav>
            </div>

            <div class="flex items-center gap-4 text-sm">
                <span class="text-[--kore-text-muted]">{{ auth()->user()?->email }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-[--kore-text-muted] hover:text-[--kore-text]">
                        {{ __('Cerrar sesión') }}
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8 space-y-6">
        @if ($title)
            <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        @endif

        {{ $slot }}
    </main>

    <livewire:kore-feedback-manager />
    <livewire:kore-overlay-manager />

    @koreScripts
    @livewireScripts
</body>
</html>
