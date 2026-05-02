<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Dashboard') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full antialiased bg-[--kore-bg] text-[--kore-text]">
    <header class="border-b border-[--kore-border]">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <div class="text-lg font-semibold">{{ config('app.name') }}</div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-[--kore-text-muted] hover:underline">
                    {{ __('Cerrar sesión') }}
                </button>
            </form>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-12 space-y-6">
        <h1 class="text-2xl font-semibold">{{ __('Hola, :name', ['name' => auth()->user()->name]) }}</h1>

        <x-kore::card>
            <p class="text-sm text-[--kore-text-muted]">
                {{ __('Estás logueado en kore-laravel. Edita esta vista en') }}
                <code>app/Modules/Auth/Resources/views/pages/dashboard.blade.php</code>.
            </p>
        </x-kore::card>
    </main>

    @livewireScripts
</body>
</html>
