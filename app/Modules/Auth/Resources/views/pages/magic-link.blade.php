<div class="space-y-6">
    <header class="space-y-1 text-center">
        <h1 class="text-xl font-semibold">{{ __('Iniciar sesión con código') }}</h1>
        <p class="text-sm text-kore-muted-fg">
            @if ($codeSent)
                {{ __('Te enviamos un código de 6 dígitos a :email', ['email' => $email]) }}
            @else
                {{ __('Te enviaremos un código a tu correo') }}
            @endif
        </p>
    </header>

    @if (! $codeSent)
        <form wire:submit="sendCode" class="space-y-4">
            <x-kore::input
                wire:model="email"
                name="email"
                type="email"
                label="{{ __('Correo electrónico') }}"
                required
                autofocus
            />

            <x-kore::button type="submit" class="w-full">
                {{ __('Enviar código') }}
            </x-kore::button>
        </form>
    @else
        <form wire:submit="authenticate" class="space-y-4">
            <x-kore::input-otp wire:model="code" name="code" length="6" autofocus />

            <x-kore::button type="submit" class="w-full">
                {{ __('Verificar y entrar') }}
            </x-kore::button>

            <button type="button"
                    wire:click="$set('codeSent', false)"
                    class="block w-full text-center text-sm text-kore-muted-fg hover:underline">
                {{ __('Cambiar correo') }}
            </button>
        </form>
    @endif

    <p class="text-center text-sm">
        <a href="{{ route('login') }}" class="font-medium text-kore-primary hover:underline">
            {{ __('Volver al login normal') }}
        </a>
    </p>
</div>
