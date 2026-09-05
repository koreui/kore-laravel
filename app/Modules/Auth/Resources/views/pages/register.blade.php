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

            {{-- Sólo con AUTH_INVITATIONS: sin el toggle el registro es abierto
                 y pedir un código que nadie reparte cerraría la puerta a todos.
                 Quien valida es `Auth\Fortify\CreateNewUser`, que mira el mismo
                 toggle. --}}
            @if (config('kore-app.auth.invitations'))
                <x-kore::input name="invitation_code" :label="__('Código de invitación')"
                    value="{{ old('invitation_code') }}" placeholder="ABCD1234" icon="ticket"
                    :hint="__('Te lo dio quien te invitó.')" required />
            @endif

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
