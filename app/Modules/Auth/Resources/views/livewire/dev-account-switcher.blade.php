<div>
    <x-kore::alert
        type="warning"
        :title="__('Atajo de desarrollo')"
        :description="__('Esta pantalla sólo existe con APP_ENV=local: fuera de local la ruta no está registrada. Entrar aquí cambia tu sesión por la de la cuenta elegida, sin dejar rastro ni forma de volver — elige otra cuenta para cambiar de nuevo.')"
    />

    <div class="mt-6 rounded-2xl border border-kore-border bg-kore-surface p-6">
        <h2 class="text-lg font-semibold tracking-tight">{{ __('Cuentas de demostración') }}</h2>
        <p class="mt-1 text-sm text-kore-muted-fg">
            {{ __('Sólo se listan las cuentas de un dominio reservado (.test, example.com…), que son las que siembran DatabaseSeeder y E2eSeeder.') }}
        </p>

        @forelse ($this->accountsByRole as $role => $accounts)
            <div class="mt-6">
                <div class="flex items-center gap-2">
                    <x-kore::badge :label="$role" color="secondary" size="sm" />
                    <span class="text-xs text-kore-muted-fg">{{ count($accounts) }}</span>
                </div>

                <ul class="mt-3 divide-y divide-kore-border rounded-xl border border-kore-border">
                    @foreach ($accounts as $account)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ $account->name }}</p>
                                <p class="truncate font-mono text-xs text-kore-muted-fg">{{ $account->email }}</p>
                            </div>

                            @if ($account->isCurrent)
                                <x-kore::badge :label="__('Sesión actual')" color="success" size="sm" icon="check" />
                            @else
                                <x-kore::button
                                    size="sm"
                                    variant="outline"
                                    icon="log-in"
                                    wire:click="switchTo({{ $account->id }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('Entrar') }}
                                </x-kore::button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="mt-6">
                <x-kore::empty-state
                    icon="user-round-search"
                    :title="__('No hay cuentas de demostración')"
                    :description="__('Siembra la base con `php artisan db:seed` para tener admin@example.com, o con el seeder de E2E para tener una cuenta por rol.')"
                />
            </div>
        @endforelse
    </div>
</div>
