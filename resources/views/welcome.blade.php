@php
    // Con DOCS_ENABLED=true el visor de /docs existe y es a donde se enlaza; sin
    // él, la documentación vive en GitHub. Un solo sitio decide, y las dos
    // salidas son enlaces válidos (antes /docs era un 404).
    $docsUrl = Route::has('docs.index')
        ? route('docs.index', absolute: false)
        : 'https://github.com/koreui/kore-laravel/tree/main/docs';

    $features = [
        ['icon' => 'layers', 'title' => __('Modular Monolith'), 'body' => __('Cada dominio aislado en app/Modules con su propio provider, rutas, vistas, modelos y tests.')],
        ['icon' => 'shield-check', 'title' => __('Auth completo'), 'body' => __('Fortify · Sanctum · 2FA · Magic links · Socialite. Roles + permisos con bypass de superadmin.')],
        ['icon' => 'sparkles', 'title' => __('AI-friendly'), 'body' => __('Laravel Boost MCP, CLAUDE.md, AGENTS.md y skills propios para que la IA scaffold módulos sola.')],
        ['icon' => 'gauge', 'title' => __('Calidad incluida'), 'body' => __('Pint, Larastan 8, Rector y Pest 3. Pre-commit hooks. CI con matrix PHP 8.3/8.4.')],
        ['icon' => 'building-2', 'title' => __('Multi-tenancy'), 'body' => __('stancl/tenancy v3 detrás de un toggle. Un comando lo activa: kore:tenancy:enable.')],
        ['icon' => 'rocket', 'title' => __('Producción ready'), 'body' => __('Stack Docker (PHP-FPM + Nginx + MySQL + Redis), Sentry, Pulse y health checks.')],
    ];
@endphp

<x-layouts.public :title="__('Boilerplate Laravel listo para producción')">

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 -z-10">
            <div class="absolute inset-0 bg-gradient-to-b from-kore-primary/5 via-transparent to-transparent"></div>
            <div class="absolute left-1/2 top-0 h-[600px] w-[800px] -translate-x-1/2 rounded-full bg-kore-primary/10 opacity-30 blur-3xl"></div>
        </div>

        <div class="mx-auto max-w-7xl px-4 pb-16 pt-20 text-center sm:px-6 sm:pb-20 sm:pt-28 lg:px-8">

            <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-kore-border bg-kore-surface px-4 py-1.5 text-sm">
                <span class="flex h-2 w-2 animate-pulse rounded-full bg-kore-success"></span>
                <span class="text-kore-muted-fg">v1.0 disponible</span>
                <a href="{{ $docsUrl }}" class="font-medium text-kore-primary">{{ __('Ver docs') }} &rarr;</a>
            </div>

            <h1 class="mx-auto max-w-4xl text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                {{ __('Boilerplate Laravel') }}<br>
                <span class="bg-gradient-to-r from-kore-primary to-kore-info bg-clip-text text-transparent">
                    {{ __('listo para construir') }}
                </span>
            </h1>

            <p class="mx-auto mt-6 max-w-2xl text-lg text-kore-muted-fg sm:text-xl">
                {{ __('Modular monolith con auth, multi-tenancy opcional, observabilidad, AI tooling y un módulo Users de referencia. Clonar y construir.') }}
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-kore-primary px-6 py-3 text-base font-semibold text-white shadow-lg shadow-kore-primary/25 transition-opacity hover:opacity-90">
                    {{ __('Empezar ahora') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                <a href="{{ route('login') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-kore-border bg-kore-surface px-6 py-3 text-base font-semibold transition-colors hover:bg-kore-muted">
                    {{ __('Iniciar sesión') }}
                </a>
            </div>

            <div class="mx-auto mt-10 max-w-md" x-data="{ copied: false }">
                <div class="flex items-center gap-2 rounded-xl border border-kore-border bg-kore-surface px-4 py-3 font-mono text-sm">
                    <span class="text-kore-muted-fg">$</span>
                    <span class="flex-1 text-left">composer create-project kore/kore-laravel</span>
                    <button @click="navigator.clipboard.writeText('composer create-project kore/kore-laravel'); copied = true; setTimeout(() => copied = false, 2000)"
                            class="text-kore-muted-fg transition-colors hover:text-kore-fg">
                        <svg x-show="!copied" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <svg x-show="copied" x-cloak class="h-4 w-4 text-kore-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Stats ─────────────────────────────────────────────── --}}
    <section class="border-y border-kore-border bg-kore-surface/50">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 gap-6 sm:grid-cols-4">
                @foreach ([
                    ['value' => __('Pest'), 'label' => __('Suite de tests')],
                    ['value' => '8.3+', 'label' => 'PHP'],
                    ['value' => 'L8', 'label' => 'PHPStan'],
                    ['value' => 'MIT', 'label' => __('Licencia')],
                ] as $s)
                    <div class="text-center">
                        <div class="text-3xl font-bold text-kore-primary">{{ $s['value'] }}</div>
                        <div class="mt-1 text-sm text-kore-muted-fg">{{ $s['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Features ─────────────────────────────────────────── --}}
    <section id="features" class="py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-12 text-center">
                <h2 class="text-3xl font-bold tracking-tight">{{ __('Todo lo que necesitas') }}</h2>
                <p class="mt-3 text-lg text-kore-muted-fg">{{ __('Para que dejes de armar el mismo setup en cada proyecto.') }}</p>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $f)
                    <div class="rounded-xl border border-kore-border bg-kore-surface p-6 transition-colors hover:border-kore-primary/50">
                        <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-kore-primary/10">
                            <x-kore::icon :name="$f['icon']" class="h-5 w-5 text-kore-primary" />
                        </div>
                        <h3 class="mb-2 font-semibold">{{ $f['title'] }}</h3>
                        <p class="text-sm text-kore-muted-fg">{{ $f['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Showcase de componentes (vivos) ──────────────────── --}}
    <section class="border-t border-kore-border bg-kore-surface/30 py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-12 text-center">
                <h2 class="text-3xl font-bold tracking-tight">{{ __('koreUi por dentro') }}</h2>
                <p class="mt-3 text-lg text-kore-muted-fg">{{ __('Componentes reales, no screenshots.') }}</p>
            </div>

            <div class="grid gap-8 lg:grid-cols-2">
                <div class="rounded-xl border border-kore-border bg-kore-bg p-6 sm:p-8">
                    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-kore-muted-fg">{{ __('Formularios') }}</h3>
                    <div class="space-y-4">
                        <x-kore::input :label="__('Nombre completo')" placeholder="Ada Lovelace" icon="user" />
                        <x-kore::input :label="__('Email')" type="email" placeholder="ada@example.com" icon="mail" />
                        <x-kore::toggle :label="__('Notificaciones por correo')" />
                        <div class="flex gap-3 pt-2">
                            <x-kore::button :label="__('Guardar')" icon="check" />
                            <x-kore::button :label="__('Cancelar')" variant="outline" />
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="rounded-xl border border-kore-border bg-kore-bg p-6">
                        <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-kore-muted-fg">{{ __('Botones') }}</h3>
                        <div class="flex flex-wrap gap-3">
                            <x-kore::button :label="__('Primary')" />
                            <x-kore::button :label="__('Outline')" variant="outline" />
                            <x-kore::button :label="__('Ghost')" variant="ghost" />
                            <x-kore::button :label="__('Soft')" variant="soft" />
                            <x-kore::button :label="__('Destructive')" color="destructive" />
                        </div>
                    </div>

                    <div class="rounded-xl border border-kore-border bg-kore-bg p-6">
                        <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-kore-muted-fg">{{ __('Alertas') }}</h3>
                        <div class="space-y-3">
                            <x-kore::alert type="info" :title="__('Información')" :description="__('Tu sesión expira en 15 minutos.')" />
                            <x-kore::alert type="success" :title="__('Listo')" :description="__('El módulo Users se generó correctamente.')" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA ──────────────────────────────────────────────── --}}
    <section class="border-t border-kore-border">
        <div class="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:px-8">
            <h2 class="text-3xl font-bold tracking-tight">{{ __('¿Listo para empezar?') }}</h2>
            <p class="mx-auto mt-3 max-w-lg text-lg text-kore-muted-fg">
                {{ __('Clona, instala dependencias, levanta composer dev y ya tienes auth, tenancy y un módulo Users completo.') }}
            </p>
            <div class="mt-8 flex justify-center gap-4">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-kore-primary px-6 py-3 text-base font-semibold text-white shadow-lg shadow-kore-primary/25 transition-opacity hover:opacity-90">
                    {{ __('Crear mi cuenta') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
            </div>
        </div>
    </section>

    {{-- ── Footer ───────────────────────────────────────────── --}}
    <footer class="border-t border-kore-border bg-kore-surface">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center gap-4 sm:flex-row sm:justify-between">
                <div class="flex items-center gap-2">
                    <div class="flex h-6 w-6 items-center justify-center rounded bg-kore-primary text-xs font-bold text-white">K</div>
                    <span class="text-sm text-kore-muted-fg">{{ config('app.name') }} &copy; {{ now()->year }}. MIT License.</span>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <a href="{{ $docsUrl }}" class="text-kore-muted-fg transition-colors hover:text-kore-fg">{{ __('Docs') }}</a>
                    <a href="/up" class="inline-flex items-center gap-1.5 text-kore-muted-fg transition-colors hover:text-kore-fg">
                        <span class="h-1.5 w-1.5 rounded-full bg-kore-success"></span>
                        {{ __('Estado') }}
                    </a>
                    <a href="https://github.com/koreui/kore-laravel" class="text-kore-muted-fg transition-colors hover:text-kore-fg">GitHub</a>
                </div>
            </div>
        </div>
    </footer>

</x-layouts.public>
