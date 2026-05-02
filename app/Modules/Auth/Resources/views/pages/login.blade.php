<x-auth::layouts.auth :title="__('Iniciar sesión')">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('Bienvenido de vuelta') }}</h1>
        <p class="text-sm text-[--kore-text-muted]">{{ __('Accede a tu cuenta para continuar.') }}</p>
    </header>

    @if (session('status'))
        <x-kore::alert type="info">{{ session('status') }}</x-kore::alert>
    @endif

    @if ($errors->any())
        <x-kore::alert type="error">{{ $errors->first() }}</x-kore::alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-kore::input
            name="email"
            type="email"
            label="{{ __('Correo electrónico') }}"
            value="{{ old('email') }}"
            placeholder="tu@email.com"
            required
            autofocus
        />

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium">{{ __('Contraseña') }}</span>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs font-medium text-[--kore-primary] hover:underline">
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </a>
                @endif
            </div>
            <x-kore::password name="password" required />
        </div>

        <x-kore::checkbox name="remember" :label="__('Mantener sesión iniciada')" />

        <x-kore::button type="submit" class="w-full" icon="arrow-right" iconPosition="trailing">
            {{ __('Entrar') }}
        </x-kore::button>
    </form>

    @if ((bool) config('kore-app.auth.magic_links') || (bool) config('kore-app.auth.social_login'))
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <span class="w-full border-t border-[--kore-border]"></span>
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-[--kore-bg] px-3 text-[--kore-text-muted]">{{ __('o continúa con') }}</span>
            </div>
        </div>

        <div class="space-y-2">
            @if ((bool) config('kore-app.auth.magic_links'))
                <a href="{{ route('magic-link.request') }}"
                   class="flex w-full items-center justify-center gap-2 rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated] px-4 py-2.5 text-sm font-medium transition-colors hover:bg-[--kore-bg-muted]">
                    <x-kore::icon name="mail" class="size-4" />
                    {{ __('Código por email') }}
                </a>
            @endif

            @if ((bool) config('kore-app.auth.social_login'))
                <div class="grid grid-cols-1 gap-2 @if (config('kore-app.socialite.google') && config('kore-app.socialite.github')) sm:grid-cols-2 @endif">
                    @if ((bool) config('kore-app.socialite.google'))
                        <a href="{{ route('socialite.redirect', 'google') }}"
                           class="flex items-center justify-center gap-2 rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated] px-4 py-2.5 text-sm font-medium transition-colors hover:bg-[--kore-bg-muted]">
                            <x-kore::icon name="chrome" class="size-4" />
                            Google
                        </a>
                    @endif
                    @if ((bool) config('kore-app.socialite.github'))
                        <a href="{{ route('socialite.redirect', 'github') }}"
                           class="flex items-center justify-center gap-2 rounded-lg border border-[--kore-border] bg-[--kore-bg-elevated] px-4 py-2.5 text-sm font-medium transition-colors hover:bg-[--kore-bg-muted]">
                            <x-kore::icon name="github" class="size-4" />
                            GitHub
                        </a>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <p class="text-center text-sm text-[--kore-text-muted]">
        {{ __('¿No tienes cuenta?') }}
        <a href="{{ route('register') }}" class="font-medium text-[--kore-primary] hover:underline">
            {{ __('Crear cuenta') }}
        </a>
    </p>
</x-auth::layouts.auth>
