<?php

declare(strict_types=1);

namespace App\Modules\Docs\Support;

use App\Modules\Docs\Data\DocumentData;
use Illuminate\Support\Str;

/**
 * Convierte un `.md` de `docs/` en el `DocumentData` que pinta el visor.
 *
 * Usa `Str::markdown()`, es decir el `GithubFlavoredMarkdownConverter` que
 * Laravel ya trae (league/commonmark): tablas, listas de tareas y bloques de
 * código sin instalar nada. Dos opciones importan:
 *
 * - `html_input => 'strip'` — el HTML crudo del Markdown se tira en vez de
 *   copiarse a la salida. Los docs son del repositorio y son de fiar, pero la
 *   plantilla los pinta con `{!! !!}` y esta es la única frontera que hay: un
 *   `<script>` pegado en un `.md` no debe poder ejecutarse por haberse leído.
 * - `allow_unsafe_links => false` — descarta `javascript:` y compañía.
 *
 * El título es el primer `# ` del documento y se **quita** del cuerpo: la
 * plantilla lo pinta como `<h1>` de la página, y dejarlo también dentro de la
 * prosa daría dos `<h1>` en la misma pantalla.
 *
 * La reescritura de enlaces y las anclas de los encabezados viven en
 * {@see DocLinkExtension}.
 */
final class MarkdownRenderer
{
    /** El repositorio público del boilerplate: lo que hay fuera de `docs/` se enlaza allí. */
    public const string REPOSITORY_URL = 'https://github.com/koreui/kore-laravel';

    /**
     * @param string $path ruta del documento relativa a `docs/` y sin `.md` (`architecture/rules`)
     */
    public function render(string $markdown, string $path): DocumentData
    {
        $extension = new DocLinkExtension($path);
        $title = $this->title($markdown);

        return new DocumentData(
            path: $path,
            title: $title ?? $path,
            html: Str::markdown(
                $title === null ? $markdown : $this->withoutTitle($markdown),
                ['html_input' => 'strip', 'allow_unsafe_links' => false],
                [$extension],
            ),
            headings: $extension->headings(),
        );
    }

    /**
     * El archivo del repositorio, en GitHub.
     *
     * @param string $repositoryPath ruta relativa a la raíz del repositorio (`docs/architecture/rules.md`)
     */
    public static function githubUrl(string $repositoryPath): string
    {
        return self::REPOSITORY_URL.'/blob/main/'.$repositoryPath;
    }

    /**
     * El primer `# ` del documento, o `null` si no empieza por un título.
     *
     * Se mira sólo la primera línea con contenido, no cualquier `# ` del
     * archivo: dentro de un bloque de código `# algo` es un comentario de
     * shell, no un encabezado.
     */
    private function title(string $markdown): ?string
    {
        $first = $this->firstMeaningfulLine($markdown);

        if ($first === null || ! str_starts_with($first, '# ')) {
            return null;
        }

        return trim(substr($first, 2));
    }

    private function withoutTitle(string $markdown): string
    {
        $first = $this->firstMeaningfulLine($markdown);

        if ($first === null) {
            return $markdown;
        }

        $position = strpos($markdown, $first);

        return $position === false
            ? $markdown
            : ltrim(substr($markdown, $position + strlen($first)), "\r\n");
    }

    private function firstMeaningfulLine(string $markdown): ?string
    {
        foreach (explode("\n", $markdown) as $line) {
            $line = rtrim($line, "\r");

            if (trim($line) !== '') {
                return $line;
            }
        }

        return null;
    }
}
