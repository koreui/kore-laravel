<x-auth::layouts.auth :title="__('Verificar correo')">
    <div class="space-y-6">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Verifica tu correo') }}</h1>
            <p class="text-sm text-[--kore-text-muted]">
                {{ __('Te enviamos un email con un enlace de verificación. Revisa tu bandeja para continuar.') }}
            </p>
        </header>

        @if (session('status') === 'verification-link-sent')
            <x-kore::alert type="success">
                {{ __('Te enviamos un nuevo enlace de verificación.') }}
            </x-kore::alert>
        @endif

        <div class="flex flex-col gap-2">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-kore::button type="submit" class="w-full">
                    {{ __('Reenviar email de verificación') }}
                </x-kore::button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-[--kore-text-muted] hover:underline">
                    {{ __('Cerrar sesión') }}
                </button>
            </form>
        </div>
    </div>
</x-auth::layouts.auth>
