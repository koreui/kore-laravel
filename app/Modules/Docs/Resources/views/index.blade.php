{{-- `/docs` · el índice maestro (docs/README.md) renderizado.

     Layout público y no el de app: `/docs` no pide sesión (los mismos textos
     están en GitHub), y `x-layouts.app` pinta la tarjeta del usuario
     autenticado, que aquí no existe. --}}
<x-layouts.public :title="$document->title">

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">

        <header class="border-b border-kore-border pb-8">
            <div class="flex flex-wrap items-center gap-3">
                <x-kore::icon name="book-open" class="size-7 text-kore-primary" />
                <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $document->title }}</h1>
            </div>

            <p class="mt-4 max-w-2xl text-kore-muted-fg">
                {{ __('Los Markdown de docs/ servidos por la propia aplicación. Se enciende con DOCS_ENABLED y se apaga en producción.') }}
            </p>

            <div class="mt-6">
                <x-kore::button
                    :href="$github"
                    target="_blank"
                    :label="__('Ver en GitHub')"
                    icon="github"
                    variant="outline"
                    size="sm" />
            </div>
        </header>

        <div class="docs-prose mt-10">{!! $document->html !!}</div>

    </div>

</x-layouts.public>
