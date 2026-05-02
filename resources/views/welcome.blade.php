@php
    $features = [
        [
            'icon' => 'layers',
            'title' => __('Modular Monolith'),
            'body' => __('Cada dominio aislado en app/Modules con su propio provider, rutas, vistas, modelos y tests.'),
            'span' => 'col-span-12 md:col-span-7',
        ],
        [
            'icon' => 'shield-check',
            'title' => __('Auth completo'),
            'body' => __('Fortify · Sanctum · 2FA · Magic links · Socialite. Roles + permisos con bypass de superadmin.'),
            'span' => 'col-span-12 md:col-span-5',
        ],
        [
            'icon' => 'sparkles',
            'title' => __('AI-friendly'),
            'body' => __('Laravel Boost MCP, CLAUDE.md, AGENTS.md y skills propios para que la IA scaffold módulos sola.'),
            'span' => 'col-span-12 md:col-span-4',
        ],
        [
            'icon' => 'gauge',
            'title' => __('Calidad incluida'),
            'body' => __('Pint, Larastan 8, Rector y Pest 3. Pre-commit hooks. CI con matrix PHP 8.3/8.4.'),
            'span' => 'col-span-12 md:col-span-4',
        ],
        [
            'icon' => 'building-2',
            'title' => __('Multi-tenancy'),
            'body' => __('stancl/tenancy v3 detrás de un toggle. `php artisan kore:tenancy:enable` y listo.'),
            'span' => 'col-span-12 md:col-span-4',
        ],
        [
            'icon' => 'rocket',
            'title' => __('Producción ready'),
            'body' => __('Stack Docker (PHP-FPM + Nginx + MySQL + Redis), Sentry, Pulse, health checks y backups.'),
            'span' => 'col-span-12 md:col-span-7',
        ],
        [
            'icon' => 'palette',
            'title' => __('koreUi por dentro'),
            'body' => __('Componentes Livewire 4 + Alpine + Tailwind v4 con tokens OKLCH y dark mode pulido.'),
            'span' => 'col-span-12 md:col-span-5',
        ],
    ];
@endphp

<x-layouts.public :title="__('Boilerplate Laravel listo para producción')">

    {{-- ── Hero asimétrico ─────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden border-b border-[--kore-border] bg-mesh">
        <div class="absolute inset-0 bg-grid opacity-30 [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_70%)]"></div>

        <div class="relative mx-auto grid max-w-6xl grid-cols-12 gap-8 px-6 py-24 md:py-32">
            <div class="col-span-12 md:col-span-7 space-y-6">
                <span class="inline-flex items-center gap-2 rounded-full border border-[--kore-border] bg-[--kore-bg-elevated]/60 px-3 py-1 text-xs font-medium text-[--kore-text-muted] backdrop-blur">
                    <x-kore::icon name="sparkles" class="size-3.5" />
                    {{ __('Laravel 12 · Livewire 4 · Tailwind v4') }}
                </span>

                <h1 class="text-5xl font-semibold leading-[1.05] tracking-tight md:text-7xl">
                    {{ __('Empieza tu próximo') }}
                    <span class="block bg-gradient-to-r from-[--kore-primary] via-[--kore-primary] to-[oklch(0.72_0.16_200)] bg-clip-text text-transparent">
                        {{ __('proyecto Laravel') }}
                    </span>
                </h1>

                <p class="max-w-xl text-base text-[--kore-text-muted] md:text-lg">
                    {{ __('Modular monolith con auth, multi-tenancy opcional, observabilidad, AI tooling y pipeline de calidad. Clonar y construir.') }}
                </p>

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <x-kore::button :href="route('register')" icon="arrow-right" iconPosition="trailing">
                        {{ __('Crear cuenta') }}
                    </x-kore::button>
                    <x-kore::button :href="route('login')" variant="outline">
                        {{ __('Iniciar sesión') }}
                    </x-kore::button>
                    <a href="https://github.com" class="inline-flex items-center gap-2 px-3 py-2 text-sm text-[--kore-text-muted] hover:text-[--kore-text]">
                        <x-kore::icon name="github" class="size-4" />
                        {{ __('Ver en GitHub') }}
                    </a>
                </div>

                <dl class="flex items-center gap-8 pt-6 text-sm">
                    <div>
                        <dt class="text-[--kore-text-muted]">{{ __('Tests') }}</dt>
                        <dd class="font-mono font-medium">32 / 32</dd>
                    </div>
                    <div>
                        <dt class="text-[--kore-text-muted]">{{ __('PHP') }}</dt>
                        <dd class="font-mono font-medium">8.3+</dd>
                    </div>
                    <div>
                        <dt class="text-[--kore-text-muted]">{{ __('PHPStan') }}</dt>
                        <dd class="font-mono font-medium">{{ __('nivel 8') }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Card flotante con preview del producto --}}
            <div class="col-span-12 md:col-span-5">
                <div class="relative rounded-2xl border border-[--kore-border] bg-[--kore-bg-elevated]/70 p-6 shadow-sm backdrop-blur">
                    <div class="flex items-center gap-2 border-b border-[--kore-border] pb-3">
                        <span class="size-2.5 rounded-full bg-rose-400/70"></span>
                        <span class="size-2.5 rounded-full bg-amber-400/70"></span>
                        <span class="size-2.5 rounded-full bg-emerald-400/70"></span>
                        <span class="ml-2 font-mono text-xs text-[--kore-text-muted]">~/kore-laravel</span>
                    </div>

                    <pre class="mt-4 overflow-x-auto font-mono text-[12.5px] leading-relaxed text-[--kore-text]"><code>$ composer create-project kore/kore-laravel
$ <span class="text-[--kore-primary]">composer dev</span>

  <span class="text-emerald-500">✓</span> Server     127.0.0.1:8000
  <span class="text-emerald-500">✓</span> Vite       hot reload
  <span class="text-emerald-500">✓</span> Queue      listening
  <span class="text-emerald-500">✓</span> Logs       streaming

$ php artisan kore:tenancy:enable
  <span class="text-emerald-500">✓</span> Tenancy ON
</code></pre>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Features bento ─────────────────────────────────────────────── --}}
    <section class="border-b border-[--kore-border]">
        <div class="mx-auto max-w-6xl px-6 py-20">
            <div class="mb-10 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-semibold tracking-tight md:text-4xl">{{ __('Todo lo que necesitas') }}</h2>
                    <p class="mt-2 max-w-xl text-[--kore-text-muted]">
                        {{ __('Hecho para que dejes de armar el mismo setup en cada proyecto y empieces a construir el negocio.') }}
                    </p>
                </div>
                <a href="/docs" class="inline-flex items-center gap-2 text-sm font-medium text-[--kore-primary] hover:underline">
                    {{ __('Leer la documentación') }}
                    <x-kore::icon name="arrow-up-right" class="size-4" />
                </a>
            </div>

            <div class="grid grid-cols-12 gap-4">
                @foreach ($features as $f)
                    <div class="{{ $f['span'] }} group relative overflow-hidden rounded-xl border border-[--kore-border] bg-[--kore-bg-elevated] p-6 transition-all duration-200 hover:-translate-y-0.5 hover:border-[--kore-primary]/40">
                        <div class="flex size-9 items-center justify-center rounded-lg border border-[--kore-border] bg-[--kore-bg] text-[--kore-primary]">
                            <x-kore::icon :name="$f['icon']" class="size-5" />
                        </div>
                        <h3 class="mt-4 text-base font-semibold">{{ $f['title'] }}</h3>
                        <p class="mt-1.5 text-sm text-[--kore-text-muted]">{{ $f['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── CTA repetido ──────────────────────────────────────────────── --}}
    <section class="border-b border-[--kore-border] bg-mesh">
        <div class="mx-auto max-w-4xl px-6 py-20 text-center">
            <h2 class="text-3xl font-semibold tracking-tight md:text-4xl">
                {{ __('¿Listo para empezar?') }}
            </h2>
            <p class="mx-auto mt-3 max-w-lg text-[--kore-text-muted]">
                {{ __('Clona, instala las dependencias, levanta `composer dev` y ya tienes auth, tenancy, observabilidad y un módulo Users completo.') }}
            </p>
            <div class="mt-6 flex justify-center gap-3">
                <x-kore::button :href="route('register')" icon="arrow-right" iconPosition="trailing">
                    {{ __('Crear mi cuenta') }}
                </x-kore::button>
                <x-kore::button :href="route('login')" variant="ghost">
                    {{ __('Iniciar sesión') }}
                </x-kore::button>
            </div>
        </div>
    </section>

    {{-- ── Footer ────────────────────────────────────────────────────── --}}
    <footer class="bg-[--kore-bg]">
        <div class="mx-auto grid max-w-6xl grid-cols-2 gap-8 px-6 py-12 md:grid-cols-4">
            <div class="col-span-2 md:col-span-1">
                <div class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</div>
                <p class="mt-2 max-w-xs text-sm text-[--kore-text-muted]">
                    {{ __('Boilerplate Laravel 12 con todo lo que necesitas para empezar.') }}
                </p>
            </div>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-[--kore-text-muted]">{{ __('Producto') }}</h4>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/docs" class="hover:text-[--kore-primary]">{{ __('Docs') }}</a></li>
                    <li><a href="{{ route('login') }}" class="hover:text-[--kore-primary]">{{ __('Iniciar sesión') }}</a></li>
                    <li><a href="{{ route('register') }}" class="hover:text-[--kore-primary]">{{ __('Crear cuenta') }}</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-[--kore-text-muted]">{{ __('Recursos') }}</h4>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/docs/architecture/overview" class="hover:text-[--kore-primary]">{{ __('Arquitectura') }}</a></li>
                    <li><a href="/docs/guides/crud" class="hover:text-[--kore-primary]">{{ __('Patrón CRUD') }}</a></li>
                    <li><a href="/docs/ops/deployment" class="hover:text-[--kore-primary]">{{ __('Deployment') }}</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-[--kore-text-muted]">{{ __('Sistema') }}</h4>
                <ul class="mt-3 space-y-2 text-sm">
                    <li>
                        <a href="/up" class="inline-flex items-center gap-2 hover:text-[--kore-primary]">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                            {{ __('Estado') }}
                        </a>
                    </li>
                    <li><a href="https://github.com" class="hover:text-[--kore-primary]">GitHub</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-[--kore-border]">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4 text-xs text-[--kore-text-muted]">
                <span>© {{ now()->year }} {{ config('app.name') }}</span>
                <span class="font-mono">v1.0.0</span>
            </div>
        </div>
    </footer>
</x-layouts.public>
