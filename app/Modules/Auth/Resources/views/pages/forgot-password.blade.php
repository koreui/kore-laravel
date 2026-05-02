<x-auth::layouts.auth :title="__('Recuperar contraseña')">
    <header class="space-y-2 text-center">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Recuperar contraseña') }}</h1>
        <p class="text-sm text-kore-muted-fg">{{ __('Te enviaremos un enlace para restablecerla.') }}</p>
    </header>

    <div class="rounded-xl border border-kore-border bg-kore-surface p-6 shadow-sm">
        @if (session('status'))
            <div class="mb-4">
                <x-kore::alert color="success" :description="session('status')" />
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4">
                <x-kore::alert color="destructive" :description="$errors->first()" />
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf

            <x-kore::input name="email" type="email" :label="__('Correo electrónico')"
                value="{{ old('email') }}" placeholder="tu@email.com" icon="mail" required autofocus />

            <button type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-kore-primary px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-kore-primary/25 transition-opacity hover:opacity-90">
                {{ __('Enviar enlace') }}
            </button>
        </form>
    </div>

    <p class="text-center text-sm">
        <a href="{{ route('login') }}" class="font-medium text-kore-muted-fg hover:text-kore-fg">
            ← {{ __('Volver al login') }}
        </a>
    </p>
</x-auth::layouts.auth>
