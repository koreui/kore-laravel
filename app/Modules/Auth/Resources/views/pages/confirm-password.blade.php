<x-auth::layouts.auth :title="__('Confirmar contraseña')">
    <div class="space-y-6">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Confirma tu contraseña') }}</h1>
            <p class="text-sm text-kore-muted-fg">
                {{ __('Confirma tu contraseña para continuar.') }}
            </p>
        </header>

        @if ($errors->any())
            <x-kore::alert type="destructive" live="assertive">{{ $errors->first() }}</x-kore::alert>
        @endif

        <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
            @csrf

            <x-kore::password name="password" label="{{ __('Contraseña') }}" required autofocus />

            <x-kore::button type="submit" class="w-full">
                {{ __('Confirmar') }}
            </x-kore::button>
        </form>
    </div>
</x-auth::layouts.auth>
