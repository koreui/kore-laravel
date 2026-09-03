@props(['title' => null])

@php
    // Igual que en la landing: si el visor de /docs está encendido (DOCS_ENABLED),
    // la documentación se lee dentro de la app; si no, en GitHub.
    $docsUrl = Route::has('docs.index')
        ? route('docs.index', absolute: false)
        : 'https://github.com/koreui/kore-laravel/tree/main/docs';
@endphp
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

    <header class="sticky top-0 z-50 border-b border-kore-border/50 bg-kore-bg/80 backdrop-blur-lg">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <a href="/" class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-kore-primary text-sm font-bold text-white">K</div>
                <span class="text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
            </a>

            <nav class="hidden items-center gap-6 text-sm md:flex">
                <a href="{{ $docsUrl }}" class="text-kore-muted-fg transition-colors hover:text-kore-fg">{{ __('Documentación') }}</a>
                <a href="/#features" class="text-kore-muted-fg transition-colors hover:text-kore-fg">{{ __('Características') }}</a>
                <a href="https://github.com/koreui/kore-laravel" class="text-kore-muted-fg transition-colors hover:text-kore-fg">GitHub</a>
            </nav>

            <div class="flex items-center gap-3">
                <x-kore::theme-switch size="sm" />
                <a href="{{ route('login') }}" class="hidden text-sm text-kore-muted-fg transition-colors hover:text-kore-fg sm:inline">
                    {{ __('Iniciar sesión') }}
                </a>
                <a href="{{ route('register') }}"
                   class="inline-flex items-center rounded-lg bg-kore-primary px-4 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90">
                    {{ __('Empezar') }}
                </a>
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
