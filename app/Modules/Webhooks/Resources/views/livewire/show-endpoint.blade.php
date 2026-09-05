<div class="space-y-6">

    {{-- El secreto de un solo uso: viene en la sesión desde el alta o desde la
         rotación, y la sesión ya lo ha consumido. En la siguiente petición esta
         tarjeta no está. --}}
    @if ($this->revealedSecret)
        <x-kore::card :title="__('Secreto de firma')">
            <div class="space-y-3">
                <x-kore::alert
                    type="warning"
                    :title="__('Cópialo ahora')"
                    :description="__('No se vuelve a mostrar. Es la clave con la que el receptor verifica la cabecera X-Kore-Signature.')" />

                <x-kore::clipboard :text="$this->revealedSecret" :label="__('Secreto')" />
            </div>
        </x-kore::card>
    @endif

    <x-kore::card :title="$this->details['name']" :subtitle="$this->details['url']">

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-kore::badge
                    :label="$this->details['active'] ? __('Activo') : __('Inactivo')"
                    :color="$this->details['active'] ? 'success' : 'muted'" />

                @foreach ($this->details['events'] as $event)
                    <x-kore::badge :label="$event" color="info" variant="outline" />
                @endforeach
            </div>

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-kore-muted-fg">{{ __('Identificador público') }}</dt>
                    <dd class="font-mono">{{ $this->details['uuid'] }}</dd>
                </div>
                <div>
                    <dt class="text-kore-muted-fg">{{ __('Creado') }}</dt>
                    <dd>{{ $this->details['created_at'] }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap gap-2">
                @foreach ($this->summary as $item)
                    <x-kore::badge :label="$item['label'].': '.$item['count']" :color="$item['color']" />
                @endforeach
            </div>
        </div>

        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                <x-kore::button :href="route('webhooks.index')" variant="ghost">
                    {{ __('Volver al listado') }}
                </x-kore::button>
                <x-kore::button :href="route('webhooks.edit', ['endpoint' => $this->details['uuid']])" variant="outline" icon="pencil">
                    {{ __('Editar') }}
                </x-kore::button>
                <x-kore::button
                    wire:click="rotateSecret"
                    wire:loading.attr="disabled"
                    wire:target="rotateSecret"
                    variant="outline"
                    color="destructive"
                    icon="key-round">
                    {{ __('Rotar secreto') }}
                </x-kore::button>
            </div>
        </x-slot:footer>
    </x-kore::card>

    <x-kore::card :title="__('Últimas entregas')" :subtitle="__('Las 50 más recientes. Lo que ya no está aquí se lo llevó webhooks:prune.')">

        <div class="space-y-4">
            <x-kore::select
                :label="__('Estado')"
                :options="$this->statusOptions"
                wire:model.live="status"
                name="status"
                native />

            @if ($this->deliveries === [])
                <x-kore::empty-state
                    :title="__('Sin entregas')"
                    :description="__('Todavía no se ha publicado ningún evento para este endpoint.')"
                    icon="inbox" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">{{ __('Entregas del endpoint') }}</caption>
                        <thead>
                            <tr class="border-b border-kore-border text-left text-xs text-kore-muted-fg">
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Evento') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Estado') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Intentos') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Respuesta') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Creada') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Siguiente intento') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-kore-border">
                            @foreach ($this->deliveries as $delivery)
                                <tr wire:key="delivery-{{ $delivery['id'] }}">
                                    <td class="px-3 py-2">
                                        <div class="font-medium">{{ $delivery['event'] }}</div>
                                        <div class="font-mono text-xs text-kore-muted-fg">{{ $delivery['uuid'] }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-kore::badge :label="$delivery['status']" :color="$delivery['color']" />
                                    </td>
                                    <td class="px-3 py-2">{{ $delivery['attempts'] }}</td>
                                    <td class="px-3 py-2">{{ $delivery['response_status'] ?? '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $delivery['created_at'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ $delivery['open'] ? ($delivery['next_attempt_at'] ?? '—') : '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($delivery['retryable'])
                                            <x-kore::button
                                                size="sm"
                                                variant="outline"
                                                icon="refresh-cw"
                                                wire:click="retryDelivery({{ $delivery['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="retryDelivery({{ $delivery['id'] }})">
                                                {{ __('Reintentar') }}
                                            </x-kore::button>
                                        @endif
                                    </td>
                                </tr>
                                <tr wire:key="delivery-detail-{{ $delivery['id'] }}">
                                    <td colspan="7" class="px-3 pb-3">
                                        {{-- `<details>` nativo: abre y cierra sin JavaScript y el
                                             lector de pantalla lo anuncia como grupo expandible. --}}
                                        <details class="rounded-md border border-kore-border bg-kore-muted/30 p-3">
                                            <summary class="cursor-pointer text-xs font-medium text-kore-muted-fg">
                                                {{ __('Ver payload y respuesta') }}
                                            </summary>

                                            @if ($delivery['last_error'])
                                                <p class="mt-2 text-sm text-kore-destructive">{{ $delivery['last_error'] }}</p>
                                            @endif

                                            <pre class="mt-2 overflow-x-auto text-xs"><code>{{ $delivery['payload'] }}</code></pre>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-kore::card>
</div>
