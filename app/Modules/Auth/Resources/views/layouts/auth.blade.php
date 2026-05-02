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

    <div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">

        {{-- ── Brand panel (visible solo en lg+) ─────────────────── --}}
        <aside class="relative hidden overflow-hidden border-r border-[--kore-border] bg-mesh lg:flex lg:flex-col lg:justify-between lg:p-10">
            <div class="absolute inset-0 bg-grid opacity-30 [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)]"></div>

            <div class="relative">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2 text-lg font-semibold tracking-tight">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-[--kore-primary] text-white">
                        <x-kore::icon name="hexagon" class="size-4" />
                    </span>
                    {{ config('app.name') }}
                </a>
            </div>

            <div class="relative max-w-md space-y-6">
                <h2 class="text-3xl font-semibold leading-tight tracking-tight">
                    {{ __('Boilerplate Laravel listo para construir tu próximo SaaS.') }}
                </h2>
                <p class="text-[--kore-text-muted]">
                    {{ __('Auth completo, multi-tenancy opcional, observabilidad, AI tooling y un módulo Users de referencia. Clonar y construir.') }}
                </p>

                <div class="grid grid-cols-3 gap-4 pt-2">
                    <div class="rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated]/60 p-3 backdrop-blur">
                        <div class="font-mono text-xl font-semibold">32</div>
                        <div class="text-xs text-[--kore-text-muted]">{{ __('Tests') }}</div>
                    </div>
                    <div class="rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated]/60 p-3 backdrop-blur">
                        <div class="font-mono text-xl font-semibold">8.3+</div>
                        <div class="text-xs text-[--kore-text-muted]">PHP</div>
                    </div>
                    <div class="rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated]/60 p-3 backdrop-blur">
                        <div class="font-mono text-xl font-semibold">v1</div>
                        <div class="text-xs text-[--kore-text-muted]">{{ __('Estable') }}</div>
                    </div>
                </div>
            </div>

            <div class="relative text-xs text-[--kore-text-muted]">
                © {{ now()->year }} {{ config('app.name') }}
            </div>
        </aside>

        {{-- ── Form panel ───────────────────────────────────────── --}}
        <section class="relative flex flex-col">

            {{-- Top bar con theme switch + link al login (móvil) --}}
            <div class="flex items-center justify-between p-6 lg:px-10">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2 text-base font-semibold lg:hidden">
                    <span class="flex size-7 items-center justify-center rounded-lg bg-[--kore-primary] text-white">
                        <x-kore::icon name="hexagon" class="size-4" />
                    </span>
                    {{ config('app.name') }}
                </a>
                <div class="ml-auto flex items-center gap-2">
                    <x-kore::theme-switch size="sm" />
                </div>
            </div>

            <div class="flex flex-1 items-center justify-center px-6 pb-12 lg:px-10">
                <div class="w-full max-w-sm space-y-6">
                    {{ $slot }}
                </div>
            </div>
        </section>
    </div>

    <livewire:kore-feedback-manager />

    @koreScripts
    @livewireScripts
</body>
</html>
