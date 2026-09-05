<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Providers;

use App\Core\Contracts\PdfRenderer;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Pdf\Support\GotenbergPdfRenderer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Módulo Pdf detrás de `PDF_ENABLED` (R10, R11).
 *
 * Con el toggle apagado no se registra **nada observable**: ni el binding de
 * `App\Core\Contracts\PdfRenderer`, ni las rutas de la vista previa, ni el gate,
 * ni las traducciones del módulo. Un proyecto derivado que no emite documentos
 * no arrastra ni el contrato resuelto ni una pantalla más que proteger.
 *
 * **Que el binding desaparezca es la decisión, no un efecto secundario.** Un
 * renderer registrado con el módulo apagado sería peor que su ausencia: quien
 * lo resolviera creería que tiene PDFs y descubriría lo contrario cuando
 * Gotenberg no respondiera, en producción. Sin binding, el error es un
 * `BindingResolutionException` inmediato y con nombre: falta encender
 * `PDF_ENABLED`.
 *
 * **No hay migraciones.** El módulo no tiene tablas: los documentos se generan
 * al vuelo desde datos que ya viven en otro sitio. Guardarlos sería mantener
 * una copia sincronizada de algo que se puede volver a imprimir.
 *
 * La única excepción al «no registra nada» es `loadViewsFrom`, y es la misma
 * que en el módulo Docs: Larastan tipa el primer argumento de `view()` como
 * `view-string` y valida el namespace contra la aplicación que arranca durante
 * el análisis. En CI no hay `.env`, así que el toggle vale su default (`false`)
 * y `composer analyse` se caería por `view('pdf::examples.sample')`, que sí está
 * en el repositorio. Registrar dónde viven las vistas no habilita nada: sin las
 * rutas no hay forma de llegar a ellas.
 *
 * Ver `docs/modules/pdf.md`.
 */
final class PdfModuleServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        if (! $this->isPdfEnabled()) {
            return;
        }

        /*
         * El único binding del módulo, y la razón de que exista el contrato:
         * los módulos consumidores piden `PdfRenderer` y no saben que detrás
         * hay spatie/laravel-pdf ni Gotenberg (R5, R7). Cambiar de motor es
         * cambiar esta línea.
         */
        $this->app->singleton(PdfRenderer::class, GotenbergPdfRenderer::class);
    }

    public function boot(): void
    {
        $base = __DIR__.'/..';

        // Siempre: el namespace de vistas no expone nada sin rutas (ver el docblock).
        $this->loadViewsFrom("{$base}/Resources/views", 'pdf');

        if (! $this->isPdfEnabled()) {
            return;
        }

        $this->loadJsonTranslationsFrom("{$base}/Resources/lang");

        $this->registerGate();

        $this->loadRoutesFrom("{$base}/Routes/web.php");
    }

    /**
     * Quién puede abrir la vista previa del tema.
     *
     * Superadmin y administrador, por lo mismo que la documentación de la API:
     * la hoja enseña la marca, el pie y el código con los que salen los
     * documentos de la empresa, y no es una pantalla que un usuario tenga por
     * qué encontrarse. No lleva permiso propio en el catálogo a propósito —un
     * permiso más en la matriz de todos los proyectos derivados por una
     * herramienta de maquetación no sale a cuenta—.
     */
    private function registerGate(): void
    {
        Gate::define(
            'viewPdfPreview',
            fn (?User $user): bool => $user instanceof User && $user->hasAnyRole([
                SystemRole::Superadmin->value,
                SystemRole::Admin->value,
            ]),
        );
    }

    private function isPdfEnabled(): bool
    {
        return (bool) config('kore-app.pdf.enabled', false);
    }
}
