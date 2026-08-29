<x-auth::layouts.auth :title="__('Verificación en dos pasos')">
    <div class="space-y-6"
         x-data="{ recovery: false }">
        <header class="space-y-1 text-center">
            <h1 class="text-xl font-semibold">{{ __('Verificación en dos pasos') }}</h1>
            <p class="text-sm text-kore-muted-fg" x-show="!recovery">
                {{ __('Ingresa el código de tu app de autenticación.') }}
            </p>
            <p class="text-sm text-kore-muted-fg" x-show="recovery" x-cloak>
                {{ __('Ingresa uno de tus códigos de recuperación.') }}
            </p>
        </header>

        @if ($errors->any())
            <x-kore::alert type="destructive" live="assertive">{{ $errors->first() }}</x-kore::alert>
        @endif

        <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
            @csrf

            <div x-show="!recovery">
                <x-kore::input-otp name="code" length="6" autofocus />
            </div>

            <div x-show="recovery" x-cloak>
                <x-kore::input name="recovery_code" label="{{ __('Código de recuperación') }}" />
            </div>

            <x-kore::button type="submit" class="w-full">
                {{ __('Verificar') }}
            </x-kore::button>
        </form>

        <button type="button"
                @click="recovery = !recovery"
                class="block w-full text-center text-sm font-medium text-kore-primary hover:underline">
            <span x-show="!recovery">{{ __('Usar código de recuperación') }}</span>
            <span x-show="recovery" x-cloak>{{ __('Usar app de autenticación') }}</span>
        </button>
    </div>
</x-auth::layouts.auth>
