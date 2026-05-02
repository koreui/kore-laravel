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

    {{-- Top nav minimal para páginas públicas --}}
    <header class="sticky top-0 z-30 border-b border-[--kore-border] bg-[--kore-bg]/80 backdrop-blur">
        <div class="mx-auto flex h-14 max-w-6xl items-center justify-between px-6">
            <a href="/" class="font-semibold tracking-tight">{{ config('app.name') }}</a>

            <nav class="hidden items-center gap-6 text-sm text-[--kore-text-muted] md:flex">
                <a href="/docs" class="hover:text-[--kore-text]">{{ __('Docs') }}</a>
                <a href="/#features" class="hover:text-[--kore-text]">{{ __('Características') }}</a>
                <a href="https://github.com" class="hover:text-[--kore-text]">GitHub</a>
            </nav>

            <div class="flex items-center gap-2">
                <x-kore::theme-switch size="sm" />
                <a href="{{ route('login') }}" class="hidden text-sm text-[--kore-text-muted] hover:text-[--kore-text] md:inline">
                    {{ __('Iniciar sesión') }}
                </a>
                <x-kore::button :href="route('register')" size="sm" icon="arrow-right" iconPosition="trailing">
                    {{ __('Empezar') }}
                </x-kore::button>
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    @koreScripts
    @livewireScripts
</body>
</html>
