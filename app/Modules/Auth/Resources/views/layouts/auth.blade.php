@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" x-data>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @koreThemeScript
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full antialiased bg-[--kore-bg] text-[--kore-text]">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <a href="{{ url('/') }}" class="text-2xl font-semibold tracking-tight">
                    {{ config('app.name') }}
                </a>
            </div>

            <x-kore::card>
                {{ $slot }}
            </x-kore::card>

            <p class="mt-6 text-center text-sm text-[--kore-text-muted]">
                {{ now()->year }} &middot; {{ config('app.name') }}
            </p>
        </div>
    </main>

    <livewire:kore-feedback-manager />

    @koreScripts
    @livewireScripts
</body>
</html>
