<?php

declare(strict_types=1);

namespace App\Modules\Docs\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Visor de `docs/` en `/docs`, detrás de `DOCS_ENABLED`.
 *
 * Con el toggle apagado no se registra ninguna ruta ni las traducciones del
 * módulo, que es lo que R10 pide de un módulo opt-in: `Route::has('docs.index')`
 * es `false` y `/docs` responde 404 sin que nadie tenga que acordarse de
 * proteger la pantalla. La única excepción es el namespace de vistas, y el
 * `boot()` explica abajo por qué.
 *
 * No hay `register()`: el `MarkdownRenderer` no necesita binding, el contenedor
 * lo resuelve solo (no tiene dependencias) y así tampoco existe cuando el
 * toggle está apagado.
 */
final class DocsModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__.'/..';

        /*
         * El namespace de vistas se registra siempre, y es la única excepción al
         * "no registra nada".
         *
         * Larastan tipa el primer argumento de `view()` como `view-string`: para
         * validarlo pregunta al `ViewFactory` de la aplicación que arranca
         * durante el análisis, y si el namespace `docs::` no existe ahí, marca
         * `view('docs::index')` como error. En CI no hay `.env`, así que el
         * toggle vale su default (`false`) y `composer analyse` se caería por un
         * archivo que sí está en el repositorio.
         *
         * Registrar la ruta de las vistas no habilita nada: sin las rutas de
         * abajo no hay forma de llegar a ellas. Lo que el toggle apaga —lo que
         * se puede observar desde fuera— sigue siendo el conjunto completo de
         * rutas, y eso es lo que comprueba DocsToggleTest.
         */
        $this->loadViewsFrom("{$base}/Resources/views", 'docs');

        if (! (bool) config('kore-app.docs.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom("{$base}/Routes/web.php");
        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");
    }
}
