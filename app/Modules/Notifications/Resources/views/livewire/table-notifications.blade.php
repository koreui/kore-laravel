{{--
    La bandeja completa: tarjetas, no tabla. Cada renglón es un mensaje que se
    lee y se atiende, no una fila de un catálogo que se ordena por columnas.

    `$notifications` son arrays ya resueltos por el componente (R30) y
    `$paginator` es el paginador, que llega aparte sólo para pintar sus enlaces.
--}}
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <x-kore::select
                :label="__('Categoría')"
                :options="$this->categoryOptions"
                wire:model.live="category"
                name="category"
                class="min-w-52"
            />

            <div class="pb-1">
                <x-kore::toggle
                    :label="__('Sólo sin leer')"
                    wire:model.live="onlyUnread"
                    name="onlyUnread"
                />
            </div>
        </div>

        @if ($this->unreadCount > 0)
            <x-kore::button
                size="sm"
                variant="outline"
                icon="check-check"
                :label="__('Marcar todas como leídas')"
                wire:click="markAllAsRead"
            />
        @endif
    </div>

    <ul class="space-y-2">
        @forelse ($notifications as $notificacion)
            <li class="flex items-start gap-3 rounded-kore-lg border p-4 transition-colors {{ $notificacion['unread'] ? 'border-kore-primary/30 bg-kore-primary/5' : 'border-kore-border bg-kore-surface' }}">
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-kore-fg">{{ $notificacion['title'] }}</span>
                        <x-kore::badge :label="$notificacion['category']" color="secondary" size="sm" />
                        @if ($notificacion['unread'])
                            <span class="size-2 rounded-full bg-kore-primary" title="{{ __('Sin leer') }}"></span>
                        @endif
                    </div>

                    <p class="text-sm text-kore-muted-fg">{{ $notificacion['body'] }}</p>
                    <p class="text-xs text-kore-muted-fg">{{ $notificacion['when'] }}</p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($notificacion['url'])
                        <x-kore::button
                            :href="$notificacion['url']"
                            :label="__('Abrir')"
                            icon="arrow-right"
                            variant="ghost"
                            size="sm"
                        />
                    @endif

                    @if ($notificacion['unread'])
                        <x-kore::button
                            :label="__('Marcar leída')"
                            icon="check"
                            variant="ghost"
                            size="sm"
                            wire:click="markAsRead('{{ $notificacion['id'] }}')"
                        />
                    @endif
                </div>
            </li>
        @empty
            <li>
                <x-kore::card>
                    <x-kore::empty-state
                        icon="bell-off"
                        :title="__('Sin notificaciones')"
                        :description="$onlyUnread || $category !== ''
                            ? __('No hay notificaciones con estos filtros.')
                            : __('Todavía no tienes notificaciones.')"
                    />
                </x-kore::card>
            </li>
        @endforelse
    </ul>

    {{ $paginator->links() }}
</div>
