{{-- `/docs/{path}` · un documento de docs/.

     Mismo layout público que el índice: la pantalla no pide sesión. El
     breadcrumb se arma con los segmentos de la ruta —sólo el primero navega,
     porque `docs/architecture` es una carpeta y no una página. --}}
@php
    $breadcrumbs = [['label' => __('Docs'), 'url' => route('docs.index')]];

    foreach (explode('/', $document->path) as $segment) {
        $breadcrumbs[] = ['label' => $segment];
    }
@endphp

<x-layouts.public :title="$document->title">

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

        <x-kore::breadcrumbs :items="$breadcrumbs" size="sm" class="mb-6" />

        <div class="lg:flex lg:gap-12">

            <article class="min-w-0 flex-1">
                <header class="border-b border-kore-border pb-6">
                    <h1 class="text-3xl font-bold tracking-tight">{{ $document->title }}</h1>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-kore::button
                            :href="route('docs.index')"
                            :label="__('Índice')"
                            icon="arrow-left"
                            variant="ghost"
                            size="sm" />

                        <x-kore::button
                            :href="$github"
                            target="_blank"
                            :label="__('Ver en GitHub')"
                            icon="github"
                            variant="outline"
                            size="sm" />
                    </div>
                </header>

                <div class="docs-prose mt-8">{!! $document->html !!}</div>
            </article>

            @if (count($document->headings) > 2)
                {{-- Índice lateral. `aria-labelledby` en vez de `aria-label` para
                     que el título visible sea también el nombre accesible. --}}
                <nav aria-labelledby="docs-en-esta-pagina"
                     class="mt-12 shrink-0 border-t border-kore-border pt-6 lg:sticky lg:top-24 lg:mt-0 lg:w-60 lg:self-start lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                    <h2 id="docs-en-esta-pagina" class="text-xs font-semibold uppercase tracking-wide text-kore-muted-fg">
                        {{ __('En esta página') }}
                    </h2>

                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($document->headings as $heading)
                            <li>
                                <a href="#{{ $heading['id'] }}"
                                   class="block text-kore-muted-fg transition-colors hover:text-kore-fg">
                                    {{ $heading['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

        </div>

    </div>

</x-layouts.public>
