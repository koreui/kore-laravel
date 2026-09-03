<?php

declare(strict_types=1);

namespace App\Modules\Docs\Http\Controllers;

use App\Modules\Docs\Data\DocumentData;
use App\Modules\Docs\Support\MarkdownRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * Sirve los `.md` de `docs/` con la UI de la app.
 *
 * El índice es `docs/README.md` —el índice maestro que ya mantiene `R40`—, así
 * que no hay una segunda lista que se quede desfasada.
 */
final class DocsController extends Controller
{
    /** El documento que se sirve en `/docs`. */
    private const string INDEX = 'README';

    public function __construct(private readonly MarkdownRenderer $renderer) {}

    public function index(): View
    {
        return view('docs::index', [
            'document' => $this->document(self::INDEX),
            'github' => MarkdownRenderer::githubUrl('docs/'.self::INDEX.'.md'),
        ]);
    }

    public function show(string $path): View
    {
        return view('docs::show', [
            'document' => $this->document($path),
            'github' => MarkdownRenderer::githubUrl('docs/'.$path.'.md'),
        ]);
    }

    private function document(string $path): DocumentData
    {
        $file = $this->resolve($path);

        if ($file === null) {
            abort(404);
        }

        return $this->renderer->render((string) file_get_contents($file), $path);
    }

    /**
     * La ruta absoluta del `.md`, o `null` si no se puede servir.
     *
     * La expresión de la ruta ya deja fuera el punto —y con él `..` y
     * `..%2F`—, pero la comprobación se repite aquí sobre la ruta ya resuelta:
     * el día que alguien relaje el `where()` de `Routes/web.php`, un
     * `/docs/../.env` tiene que seguir siendo un 404 y no el `.env`. `realpath`
     * también resuelve los enlaces simbólicos, así que un symlink dentro de
     * `docs/` que apunte fuera tampoco cuela.
     */
    private function resolve(string $path): ?string
    {
        $root = realpath(base_path('docs'));

        if ($root === false) {
            return null;
        }

        $file = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path).'.md');

        if ($file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }
}
