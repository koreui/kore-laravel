<x-auth::layouts.auth :title="__('Iniciar sesión')">
    <div class="space-y-6">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Iniciar sesión') }}</h1>
            <p class="text-sm text-[--kore-text-muted]">{{ __('Accede a tu cuenta') }}</p>
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
                required
                autofocus
            />

            <x-kore::password
                name="password"
                label="{{ __('Contraseña') }}"
                required
            />

            <div class="flex items-center justify-between">
                <x-kore::checkbox name="remember" label="{{ __('Recordarme') }}" />

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-[--kore-primary] hover:underline">
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </a>
                @endif
            </div>

            <x-kore::button type="submit" class="w-full">
                {{ __('Entrar') }}
            </x-kore::button>
        </form>

        @if ((bool) config('kore-app.auth.magic_links'))
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <span class="w-full border-t border-[--kore-border]"></span>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-[--kore-bg-elevated] px-2 text-[--kore-text-muted]">{{ __('o') }}</span>
                </div>
            </div>

            <a href="{{ route('magic-link.request') }}"
               class="block w-full text-center text-sm font-medium text-[--kore-primary] hover:underline">
                {{ __('Iniciar sesión con código por email') }}
            </a>
        @endif

        @if ((bool) config('kore-app.auth.social_login'))
            <div class="grid grid-cols-1 gap-2">
                @if ((bool) config('kore-app.socialite.google'))
                    <a href="{{ route('socialite.redirect', 'google') }}"
                       class="inline-flex items-center justify-center gap-2 rounded-md border border-[--kore-border] px-4 py-2 text-sm hover:bg-[--kore-bg-muted]">
                        <x-lucide-mail class="size-4" /> {{ __('Continuar con Google') }}
                    </a>
                @endif
                @if ((bool) config('kore-app.socialite.github'))
                    <a href="{{ route('socialite.redirect', 'github') }}"
                       class="inline-flex items-center justify-center gap-2 rounded-md border border-[--kore-border] px-4 py-2 text-sm hover:bg-[--kore-bg-muted]">
                        <x-lucide-github class="size-4" /> {{ __('Continuar con GitHub') }}
                    </a>
                @endif
            </div>
        @endif

        <p class="text-center text-sm text-[--kore-text-muted]">
            {{ __('¿No tienes cuenta?') }}
            <a href="{{ route('register') }}" class="font-medium text-[--kore-primary] hover:underline">
                {{ __('Crear cuenta') }}
            </a>
        </p>
    </div>
</x-auth::layouts.auth>
