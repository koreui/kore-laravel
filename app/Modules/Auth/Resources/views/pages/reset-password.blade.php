<x-auth::layouts.auth :title="__('Restablecer contraseña')">
    <div class="space-y-6">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Restablecer contraseña') }}</h1>
        </header>

        @if ($errors->any())
            <x-kore::alert type="error">{{ $errors->first() }}</x-kore::alert>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <x-kore::input name="email" type="email" label="{{ __('Correo electrónico') }}"
                          value="{{ old('email', $request->email) }}" required />

            <x-kore::password name="password" label="{{ __('Nueva contraseña') }}" required />

            <x-kore::password name="password_confirmation" label="{{ __('Confirmar contraseña') }}" required />

            <x-kore::button type="submit" class="w-full">
                {{ __('Restablecer contraseña') }}
            </x-kore::button>
        </form>
    </div>
</x-auth::layouts.auth>
