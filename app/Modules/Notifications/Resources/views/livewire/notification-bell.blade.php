{{--
    La campana del encabezado.

    El panel se abre con Alpine y no con un viaje al servidor: mostrar cinco
    elementos que ya están en el DOM no justifica una petición. Lo que sí viaja
    es el refresco del contador, con `wire:poll` cada
    `kore-notifications.bell.poll_seconds` (0 lo apaga).

    Todo lo que se pinta llega como array desde el componente (R30): aquí no hay
    ni una consulta ni un formateo de fecha.
--}}
<div
    x-data="{ abierto: false }"
    @click.outside="abierto = false"
    class="relative"
    @if ($this->pollSeconds > 0) wire:poll.{{ $this->pollSeconds }}s="refreshInbox" @endif
>
    <button
        type="button"
        @click="abierto = ! abierto"
        :aria-expanded="abierto"
        aria-haspopup="true"
        {{-- El nombre accesible se declara y no se deduce: con `title` a secas,
             en cuanto aparece el globo el botón pasa a llamarse «1» y quien lo
             busca por nombre —una persona con lector de pantalla o un test— deja
             de encontrarlo. --}}
        aria-label="{{ $this->unreadCount > 0
            ? __('Notificaciones: :count sin leer', ['count' => $this->unreadCount])
            : __('Notificaciones') }}"
        class="relative rounded-kore-md p-2 text-kore-muted-fg transition-colors hover:bg-kore-muted hover:text-kore-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kore-ring/50"
    >
        <x-kore::icon name="bell" size="lg" />

        @if ($this->unreadCount > 0)
            <span
                aria-hidden="true"
                class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-kore-destructive px-1 text-[10px] font-bold leading-none text-kore-destructive-fg"
            >{{ $this->badge }}</span>
        @endif
    </button>

    <div
        x-show="abierto"
        x-cloak
        x-transition.origin.top.right
        class="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-kore-lg border border-kore-border bg-kore-surface shadow-lg"
    >
        <div class="flex items-center justify-between gap-2 border-b border-kore-border px-4 py-2.5">
            <span class="text-sm font-semibold text-kore-fg">{{ __('Notificaciones') }}</span>

            @if ($this->unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    class="text-xs text-kore-primary-text hover:underline"
                >{{ __('Marcar todas') }}</button>
            @endif
        </div>

        <ul class="max-h-80 divide-y divide-kore-border/60 overflow-y-auto">
            @forelse ($this->latest as $notificacion)
                <li class="{{ $notificacion['unread'] ? 'bg-kore-primary/5' : '' }}">
                    <a
                        href="{{ $notificacion['url'] ?? route('notifications.index') }}"
                        class="flex gap-3 px-4 py-3 transition-colors hover:bg-kore-muted"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-kore-fg">{{ $notificacion['title'] }}</p>
                            <p class="line-clamp-2 text-xs text-kore-muted-fg">{{ $notificacion['body'] }}</p>
                            <p class="mt-0.5 text-[11px] text-kore-muted-fg">
                                {{ $notificacion['category'] }} · {{ $notificacion['when'] }}
                            </p>
                        </div>

                        @if ($notificacion['unread'])
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-kore-primary" aria-hidden="true"></span>
                        @endif
                    </a>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-kore-muted-fg">
                    {{ __('Sin notificaciones.') }}
                </li>
            @endforelse
        </ul>

        <a
            href="{{ route('notifications.index') }}"
            class="block border-t border-kore-border px-4 py-2.5 text-center text-sm text-kore-primary-text hover:bg-kore-muted"
        >{{ __('Ver todas') }}</a>
    </div>
</div>
