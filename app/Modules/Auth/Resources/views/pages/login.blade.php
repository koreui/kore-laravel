<x-auth::layouts.auth :title="__('Iniciar sesión')">
    <header class="space-y-2 text-center">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Bienvenido de vuelta') }}</h1>
        <p class="text-sm text-kore-muted-fg">{{ __('Accede a tu cuenta para continuar.') }}</p>
    </header>

    <div class="rounded-xl border border-kore-border bg-kore-surface p-6 shadow-sm">

        @if (session('status'))
            <div class="mb-4">
                <x-kore::alert type="info" live="polite" :description="session('status')" />
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4">
                <x-kore::alert type="destructive" live="assertive" :description="$errors->first()" />
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <x-kore::input
                name="email"
                type="email"
                :label="__('Correo electrónico')"
                value="{{ old('email') }}"
                placeholder="tu@email.com"
                icon="mail"
                required
                autofocus
            />

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">{{ __('Contraseña') }}</span>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-kore-primary hover:underline">
                            {{ __('¿Olvidaste tu contraseña?') }}
                        </a>
                    @endif
                </div>
                <x-kore::password name="password" required />
            </div>

            <x-kore::checkbox name="remember" :label="__('Mantener sesión iniciada')" />

            <button type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-kore-primary px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-kore-primary/25 transition-opacity hover:opacity-90">
                {{ __('Entrar') }}
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </button>
        </form>

        @if (Route::has('passkey.login') || (bool) config('kore-app.auth.magic_links') || (bool) config('kore-app.auth.social_login'))
            <div class="relative my-5">
                <div class="absolute inset-0 flex items-center">
                    <span class="w-full border-t border-kore-border"></span>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-kore-surface px-3 text-kore-muted-fg">{{ __('o continúa con') }}</span>
                </div>
            </div>

            <div class="space-y-2">
                {{-- Passkeys. `Route::has` en vez del toggle: es Fortify quien
                     publica el endpoint, así que preguntar por la ruta cubre
                     también el caso de que la feature se quite desde
                     `fortify.features` sin tocar `kore-app`. --}}
                @if (Route::has('passkey.login'))
                    <div x-data="korePasskeys(@js([
                        'cancelled' => __('Has cancelado la operación o el dispositivo no la ha confirmado.'),
                        'unsupported' => __('Este navegador no admite passkeys.'),
                        'exists' => __('Este dispositivo ya tiene una passkey registrada en tu cuenta.'),
                        'domain' => __('Las passkeys no se pueden usar en este dominio.'),
                        'failed' => __('No hemos podido completar la operación. Inténtalo de nuevo.'),
                        'redirect' => route('dashboard'),
                    ]))">
                        <button type="button"
                                x-on:click="signInWithPasskey()"
                                x-bind:disabled="busy"
                                class="flex w-full items-center justify-center gap-2 rounded-xl border border-kore-border bg-kore-bg px-4 py-2.5 text-sm font-medium transition-colors hover:bg-kore-muted disabled:opacity-50">
                            <x-kore::icon name="key-round" class="h-4 w-4" />
                            {{ __('Entrar con passkey') }}
                        </button>

                        {{-- El error se pinta sin recargar: cancelar el diálogo
                             del sistema o usar un navegador sin WebAuthn no es
                             un fallo del servidor y no tiene por qué costar una
                             navegación. --}}
                        <template x-if="error">
                            <div class="mt-2">
                                <x-kore::alert type="destructive" live="assertive">
                                    <span x-text="error"></span>
                                </x-kore::alert>
                            </div>
                        </template>
                    </div>
                @endif

                @if ((bool) config('kore-app.auth.magic_links'))
                    <a href="{{ route('magic-link.request') }}"
                       class="flex w-full items-center justify-center gap-2 rounded-xl border border-kore-border bg-kore-bg px-4 py-2.5 text-sm font-medium transition-colors hover:bg-kore-muted">
                        <x-kore::icon name="mail" class="h-4 w-4" />
                        {{ __('Código por email') }}
                    </a>
                @endif

                @if ((bool) config('kore-app.auth.social_login'))
                    <div class="grid gap-2 @if (config('kore-app.socialite.google') && config('kore-app.socialite.github')) sm:grid-cols-2 @endif">
                        @if ((bool) config('kore-app.socialite.google'))
                            <a href="{{ route('socialite.redirect', 'google') }}"
                               class="flex items-center justify-center gap-2 rounded-xl border border-kore-border bg-kore-bg px-4 py-2.5 text-sm font-medium transition-colors hover:bg-kore-muted">
                                <x-kore::icon name="chrome" class="h-4 w-4" />
                                Google
                            </a>
                        @endif
                        @if ((bool) config('kore-app.socialite.github'))
                            <a href="{{ route('socialite.redirect', 'github') }}"
                               class="flex items-center justify-center gap-2 rounded-xl border border-kore-border bg-kore-bg px-4 py-2.5 text-sm font-medium transition-colors hover:bg-kore-muted">
                                <x-kore::icon name="github" class="h-4 w-4" />
                                GitHub
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    <p class="text-center text-sm text-kore-muted-fg">
        {{ __('¿No tienes cuenta?') }}
        <a href="{{ route('register') }}" class="font-medium text-kore-primary hover:underline">
            {{ __('Crear cuenta') }}
        </a>
    </p>
</x-auth::layouts.auth>
