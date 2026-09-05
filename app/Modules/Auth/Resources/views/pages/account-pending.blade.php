{{-- Se usa el layout de auth y NO el de la aplicación a propósito: el shell
     pinta el menú lateral entero, y enseñarle a alguien que todavía no puede
     entrar la lista de todo lo que no puede abrir es la peor manera de decirle
     que espere. --}}
<x-auth::layouts.auth :title="__('Cuenta en revisión')">
    <header class="space-y-2 text-center">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Tu cuenta está en revisión') }}</h1>
        <p class="text-sm text-kore-muted-fg">{{ __('Te avisaremos en cuanto quede activada.') }}</p>
    </header>

    <div class="rounded-xl border border-kore-border bg-kore-surface p-6 shadow-sm">
        <x-kore::result
            status="info"
            icon="clock"
            :title="__('Estamos revisando tu alta')"
            :description="__('Un administrador tiene que activar tu cuenta antes de que puedas usar la aplicación. No hace falta que hagas nada más.')"
        />
    </div>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-kore-primary hover:underline">
            {{ __('Cerrar sesión') }}
        </button>
    </form>
</x-auth::layouts.auth>
