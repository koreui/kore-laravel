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

    {{-- Background gradient sutil (mismo patrón que kore-docs landing) --}}
    <div class="pointer-events-none fixed inset-0 -z-10">
        <div class="absolute inset-0 bg-gradient-to-b from-kore-primary/5 via-transparent to-transparent"></div>
        <div class="absolute left-1/2 top-0 h-[600px] w-[800px] -translate-x-1/2 rounded-full bg-kore-primary/10 opacity-30 blur-3xl"></div>
    </div>

    <div class="relative flex min-h-screen flex-col">

        {{-- Top bar minimal con logo + theme switch --}}
        <header class="flex items-center justify-between px-6 py-5 sm:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-kore-primary text-sm font-bold text-white">K</div>
                <span class="text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
            </a>
            <x-kore::theme-switch size="sm" />
        </header>

        {{-- Form card centrado --}}
        <main class="flex flex-1 items-center justify-center px-4 py-8 sm:px-6">
            <div class="w-full max-w-sm">
                <div class="space-y-6">
                    {{ $slot }}
                </div>
            </div>
        </main>

        <footer class="px-6 py-4 text-center text-xs text-kore-muted-fg">
            © {{ now()->year }} {{ config('app.name') }}
        </footer>
    </div>

    <livewire:kore-feedback-manager />

    @koreScripts
    @livewireScripts
</body>
</html>
