<x-auth::layouts.auth :title="__('Crear cuenta')">
    <header class="space-y-2 text-center">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Crear tu cuenta') }}</h1>
        <p class="text-sm text-kore-muted-fg">{{ __('Empieza en menos de un minuto.') }}</p>
    </header>

    <div class="rounded-xl border border-kore-border bg-kore-surface p-6 shadow-sm">
        @if ($errors->any())
            <div class="mb-4">
                <x-kore::alert type="destructive" live="assertive" :description="$errors->first()" />
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <x-kore::input name="name" :label="__('Nombre completo')" value="{{ old('name') }}"
                placeholder="Ada Lovelace" icon="user" required autofocus />

            <x-kore::input name="email" type="email" :label="__('Correo electrónico')"
                value="{{ old('email') }}" placeholder="tu@email.com" icon="mail" required />

            <x-kore::password name="password" :label="__('Contraseña')" required />

            <x-kore::password name="password_confirmation" :label="__('Confirmar contraseña')" required />

            <button type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-kore-primary px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-kore-primary/25 transition-opacity hover:opacity-90">
                {{ __('Crear cuenta') }}
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </button>
        </form>
    </div>

    <p class="text-center text-sm text-kore-muted-fg">
        {{ __('¿Ya tienes cuenta?') }}
        <a href="{{ route('login') }}" class="font-medium text-kore-primary hover:underline">
            {{ __('Inicia sesión') }}
        </a>
    </p>
</x-auth::layouts.auth>
