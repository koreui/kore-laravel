<?php

declare(strict_types=1);

namespace App\Modules\Docs\Data;

use App\Core\Data\Data;

/**
 * Un `.md` de `docs/` ya convertido a HTML.
 *
 * Es lo que el `MarkdownRenderer` devuelve y lo único que llega a la Blade: el
 * componente no tiene que saber que detrás hay un archivo ni un parser, y la
 * vista no tiene que buscar el título dentro del HTML (R30 · nada de lógica en
 * la plantilla).
 *
 * `path` es la ruta del documento **relativa a `docs/` y sin la extensión**
 * (`architecture/rules`), que es exactamente el segmento `{path}` de la URL.
 */
final class DocumentData extends Data
{
    /**
     * @param list<array{id: string, text: string}> $headings los `##` del documento, para el índice lateral
     */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $html,
        public readonly array $headings,
    ) {}
}
