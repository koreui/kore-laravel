<x-auth::layouts.auth :title="__('Recuperar contraseña')">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight">{{ __('Recuperar contraseña') }}</h1>
        <p class="text-sm text-[--kore-text-muted]">{{ __('Te enviaremos un enlace para restablecerla.') }}</p>
    </header>

    @if (session('status'))
        <x-kore::alert type="success">{{ session('status') }}</x-kore::alert>
    @endif

    @if ($errors->any())
        <x-kore::alert type="error">{{ $errors->first() }}</x-kore::alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-kore::input name="email" type="email" :label="__('Correo electrónico')"
            value="{{ old('email') }}" placeholder="tu@email.com" required autofocus />

        <x-kore::button type="submit" class="w-full" icon="send" iconPosition="trailing">
            {{ __('Enviar enlace') }}
        </x-kore::button>
    </form>

    <p class="text-center text-sm">
        <a href="{{ route('login') }}" class="font-medium text-[--kore-text-muted] hover:text-[--kore-text]">
            ← {{ __('Volver al login') }}
        </a>
    </p>
</x-auth::layouts.auth>
