<x-auth::layouts.auth :title="__('Crear cuenta')">
    <div class="space-y-6">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Crear cuenta') }}</h1>
            <p class="text-sm text-[--kore-text-muted]">{{ __('Completa tus datos para comenzar') }}</p>
        </header>

        @if ($errors->any())
            <x-kore::alert type="error">{{ $errors->first() }}</x-kore::alert>
        @endif

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <x-kore::input name="name" label="{{ __('Nombre') }}" value="{{ old('name') }}" required autofocus />

            <x-kore::input name="email" type="email" label="{{ __('Correo electrónico') }}" value="{{ old('email') }}" required />

            <x-kore::password name="password" label="{{ __('Contraseña') }}" required />

            <x-kore::password name="password_confirmation" label="{{ __('Confirmar contraseña') }}" required />

            <x-kore::button type="submit" class="w-full">
                {{ __('Crear cuenta') }}
            </x-kore::button>
        </form>

        <p class="text-center text-sm text-[--kore-text-muted]">
            {{ __('¿Ya tienes cuenta?') }}
            <a href="{{ route('login') }}" class="font-medium text-[--kore-primary] hover:underline">
                {{ __('Inicia sesión') }}
            </a>
        </p>
    </div>
</x-auth::layouts.auth>
